<?php

namespace App\Console\Commands;

use App\Models\FuelPriceImport;
use App\Models\Station;
use App\Models\StationPrice;
use App\Support\StationSearchCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportFuelPrices extends Command
{
    protected $signature = 'fuel:import-prices
    {--limit=5 : Número máximo de estaciones a importar en una prueba}
    {--all : Importa el catálogo completo de estaciones}
    {--confirm-full : Confirmación requerida junto con --all}
    {--force : Procesa el archivo aunque su hash ya exista para el mismo tipo de importación}';

    protected $description = 'Descarga estaciones y precios oficiales, e importa una muestra limitada';

    private const PRICES_URL = 'https://publicacionexterna.azurewebsites.net/publicaciones/prices';

    private const PLACES_URL = 'https://publicacionexterna.azurewebsites.net/publicaciones/places';

    public function handle(): int
    {
        $isFullImport = (bool) $this->option('all');

        if ($isFullImport && ! $this->option('confirm-full')) {
            $this->error('La importación completa requiere --all --confirm-full.');
            $this->line(
                'Ejemplo: php artisan fuel:import-prices --all --confirm-full'
            );

            return self::FAILURE;
        }

        $limit = $isFullImport
            ? null
            : max(1, (int) $this->option('limit'));

        $importScope = $isFullImport ? 'full' : 'limited';
        $import = null;

        try {
            $this->info('Descargando catálogo de estaciones...');
            $placesResponse = $this->download(self::PLACES_URL);

            $this->info('Descargando catálogo de precios...');
            $pricesResponse = $this->download(self::PRICES_URL);

            $placesContents = $placesResponse->body();
            $pricesContents = $pricesResponse->body();

            $pricesHash = hash('sha256', $pricesContents);

            $existingImport = FuelPriceImport::query()
                ->where('status', 'completed')
                ->where('import_scope', $importScope)
                ->where('file_hash', $pricesHash)
                ->latest('id')
                ->first();

            if ($existingImport !== null && ! $this->option('force')) {
                $this->warn(
                    'Este archivo de precios ya fue procesado anteriormente.'
                );
                $this->line("Importación anterior: #{$existingImport->id}");
                $this->line(
                    'No se creó una nueva auditoría ni se insertaron datos.'
                );
                $this->line(
                    'Usa --force únicamente si quieres reprocesarlo de forma intencional.'
                );

                return self::SUCCESS;
            }

            $this->ensureXml($placesContents, 'catálogo de estaciones');
            $this->ensureXml($pricesContents, 'catálogo de precios');

            $import = FuelPriceImport::create([
                'status' => 'running',
                'source' => 'cne',
                'import_scope' => $importScope,
                'source_url' => self::PRICES_URL,
                'started_at' => now(),
                'metadata' => [
                    'mode' => $isFullImport
                        ? 'full_station_and_price_import'
                        : 'limited_station_and_price_import',
                    'station_limit' => $limit,
                    'forced' => (bool) $this->option('force'),
                ],
            ]);

            $timestamp = now()->format('Ymd-His');

            $placesPath = "fuel-imports/places-{$timestamp}.xml";
            $pricesPath = "fuel-imports/prices-{$timestamp}.xml";

            Storage::disk('local')->put($placesPath, $placesContents);
            Storage::disk('local')->put($pricesPath, $pricesContents);

            $placesXml = simplexml_load_string($placesContents);
            $pricesXml = simplexml_load_string($pricesContents);

            if ($placesXml === false || $pricesXml === false) {
                throw new \RuntimeException(
                    'No se pudo interpretar uno de los XML descargados.'
                );
            }

            /*
             * Agrupamos los precios por place_id.
             * Un mismo place_id puede aparecer varias veces.
             */
            $pricesByPlaceId = [];

            foreach ($pricesXml->place as $pricePlace) {
                $placeId = (int) $pricePlace['place_id'];

                foreach ($pricePlace->gas_price as $gasPrice) {
                    $fuelType = (string) $gasPrice['type'];
                    $priceValue = (float) $gasPrice;

                    if (
                        in_array(
                            $fuelType,
                            ['regular', 'premium', 'diesel'],
                            true
                        ) &&
                        $priceValue > 0
                    ) {
                        $pricesByPlaceId[$placeId][$fuelType] = $priceValue;
                    }
                }
            }

            $stationsProcessed = 0;
            $stationsCreated = 0;
            $stationsUpdated = 0;
            $pricesProcessed = 0;
            $pricesCreated = 0;
            $pricesSkipped = 0;

            $importedAt = now();
            $importedStations = [];

            foreach ($placesXml->place as $place) {
                if ($limit !== null && $stationsProcessed >= $limit) {
                    break;
                }

                $placeId = (int) $place['place_id'];
                $name = trim((string) $place->name);
                $permitNumber = trim((string) $place->cre_id);
                $longitude = (float) $place->location->x;
                $latitude = (float) $place->location->y;

                if (
                    $placeId <= 0 ||
                    $permitNumber === '' ||
                    $latitude === 0.0 ||
                    $longitude === 0.0
                ) {
                    continue;
                }

                $station = Station::updateOrCreate(
                    ['place_id' => $placeId],
                    [
                        'permit_number' => $permitNumber,
                        'name' => $name !== '' ? $name : null,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'is_active' => true,
                    ]
                );

                DB::statement(
                    'UPDATE stations
                     SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                     WHERE id = ?',
                    [$longitude, $latitude, $station->id]
                );

                $stationsProcessed++;
                $importedStations[$placeId] = $station;

                if ($station->wasRecentlyCreated) {
                    $stationsCreated++;
                } else {
                    $stationsUpdated++;
                }
            }

            foreach ($importedStations as $placeId => $station) {
                $stationPrices = $pricesByPlaceId[$placeId] ?? [];

                if ($stationPrices === []) {
                    $pricesSkipped++;
                    continue;
                }

                foreach ($stationPrices as $fuelType => $priceValue) {
                    $price = StationPrice::firstOrCreate(
                        [
                            'station_id' => $station->id,
                            'fuel_type' => $fuelType,
                            'source' => 'cne',
                            'imported_at' => $importedAt,
                        ],
                        [
                            'price' => $priceValue,
                            'reported_at' => null,
                        ]
                    );

                    $pricesProcessed++;

                    if ($price->wasRecentlyCreated) {
                        $pricesCreated++;
                    } else {
                        $pricesSkipped++;
                    }
                }

                $station->update([
                    'last_official_update_at' => $importedAt,
                ]);
            }

            /*
             * Al terminar correctamente la importación, cambiamos
             * la versión de cache. Las siguientes búsquedas usarán
             * nuevas keys y no mostrarán resultados anteriores.
             */
            $cacheVersion = StationSearchCache::invalidate();

            $import->update([
                'status' => 'completed',
                'file_path' => $pricesPath,
                'file_name' => basename($pricesPath),
                'file_hash' => $pricesHash,
                'file_size_bytes' => strlen($pricesContents),
                'stations_processed' => $stationsProcessed,
                'stations_created' => $stationsCreated,
                'stations_updated' => $stationsUpdated,
                'prices_processed' => $pricesProcessed,
                'prices_created' => $pricesCreated,
                'prices_skipped' => $pricesSkipped,
                'finished_at' => now(),
                'metadata' => [
                    'mode' => $isFullImport
                        ? 'full_station_and_price_import'
                        : 'limited_station_and_price_import',
                    'station_limit' => $limit,
                    'forced' => (bool) $this->option('force'),
                    'cache_version' => $cacheVersion,
                    'prices' => [
                        'url' => self::PRICES_URL,
                        'path' => $pricesPath,
                        'hash' => $pricesHash,
                        'size_bytes' => strlen($pricesContents),
                    ],
                    'places' => [
                        'url' => self::PLACES_URL,
                        'path' => $placesPath,
                        'hash' => hash('sha256', $placesContents),
                        'size_bytes' => strlen($placesContents),
                    ],
                ],
            ]);

            $this->info(
                $isFullImport
                    ? 'Importación completa terminada.'
                    : 'Importación limitada terminada.'
            );

            $this->line("Importación #{$import->id}");
            $this->line("Estaciones procesadas: {$stationsProcessed}");
            $this->line("Estaciones creadas: {$stationsCreated}");
            $this->line("Estaciones actualizadas: {$stationsUpdated}");
            $this->line("Precios procesados: {$pricesProcessed}");
            $this->line("Precios creados: {$pricesCreated}");
            $this->line(
                "Precios sin coincidencia/omitidos: {$pricesSkipped}"
            );
            $this->line(
                "Cache de estaciones invalidado. Nueva versión: {$cacheVersion}"
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($import !== null) {
                $import->update([
                    'status' => 'failed',
                    'errors_count' => 1,
                    'error_message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);
            }

            $this->error('La importación falló: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    private function download(string $url)
    {
        $response = Http::timeout(120)
            ->retry(2, 3000)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "La fuente {$url} respondió HTTP {$response->status()}."
            );
        }

        if ($response->body() === '') {
            throw new \RuntimeException(
                "La descarga de {$url} llegó vacía."
            );
        }

        return $response;
    }

    private function ensureXml(string $contents, string $label): void
    {
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($contents);

        if ($xml === false || $xml->getName() !== 'places') {
            throw new \RuntimeException(
                "El {$label} no contiene un XML válido con nodo raíz <places>."
            );
        }
    }
}