<?php

namespace OC\Metadata\Provider;

use OC\Metadata\FileMetadata;
use OC\Metadata\IMetadataProvider;
use OCP\Files\File;

class ExifProvider implements IMetadataProvider {
	public static function groupsProvided(): array {
		return ['size'];
	}

	public static function isAvailable(): bool {
		return extension_loaded('exif');
	}

	public function execute(File $file): array {
		$exifData = [];
		$fileDescriptor = $file->fopen('rb');

		// Copy image data into tmp stream as exif_read_data don't work properly with our streams wrappers.
		$nativeFileDescriptorStream = fopen('php://temp', 'rw');
		$result = stream_copy_to_stream(
			$fileDescriptor,
			$nativeFileDescriptorStream,
		);

		if ($result === false) {
			fclose($nativeFileDescriptorStream);
			throw new \Exception("Failed to copy into tmp stream from fileid: " . $file->getId());
		}

		$data = exif_read_data($nativeFileDescriptorStream, 'ANY_TAG', true);
		fclose($nativeFileDescriptorStream);

		$size = new FileMetadata();
		$size->setGroupName('size');
		$size->setId($file->getId());
		$size->setMetadata([]);

		if (!$data) {
			$sizeResult = getimagesizefromstring($file->getContent());
			if ($sizeResult !== false) {
				$size->setMetadata([
					'width' => $sizeResult[0],
					'height' => $sizeResult[1],
				]);

				$exifData['size'] = $size;
			}

		} elseif (array_key_exists('COMPUTED', $data)) {
			if (array_key_exists('Width', $data['COMPUTED']) && array_key_exists('Height', $data['COMPUTED'])) {
				$size->setMetadata([
					'width' => $data['COMPUTED']['Width'],
					'height' => $data['COMPUTED']['Height'],
				]);

				$exifData['size'] = $size;
			}
		}

		if ($data && array_key_exists('GPS', $data)
			&& array_key_exists('GPSLatitude', $data['GPS']) && array_key_exists('GPSLatitudeRef', $data['GPS'])
			&& array_key_exists('GPSLongitude', $data['GPS']) && array_key_exists('GPSLongitudeRef', $data['GPS'])
		) {
			$gps = new FileMetadata();
			$gps->setGroupName('gps');
			$gps->setId($file->getId());
			$gps->setMetadata([
				'coordinate' => [
					'latitude' => $this->gpsDegreesToDecimal($data['GPS']['GPSLatitude'], $data['GPS']['GPSLatitudeRef']),
					'longitude' => $this->gpsDegreesToDecimal($data['GPS']['GPSLongitude'], $data['GPS']['GPSLongitudeRef']),
				],
			]);

			$exifData['gps'] = $gps;
		}

		return $exifData;
	}

	public static function getMimetypesSupported(): string {
		return '/image\/.*/';
	}

	/**
	 * @param array|string $coordinate
	 */
	private static function gpsDegreesToDecimal(array $coordinates, string $hemisphere): float {
		if (is_string($coordinates)) {
			$coordinates = array_map("trim", explode(",", $coordinates));
		}

		if (count($coordinates) !== 3) {
			throw new \Exception('Invalid coordinate format: ' . $coordinates);
		}

		[$degrees, $minutes, $seconds] = array_map(function (string $rawDegree) {
			$parts = explode('/', $rawDegree);
			return floatval($parts[0])/floatval($parts[1] ?? 1);
		}, $coordinates);

		$sign = ($hemisphere === 'W' || $hemisphere === 'S') ? -1 : 1;
		return $sign * ($degrees + $minutes/60 + $seconds/3600);
	}
}
