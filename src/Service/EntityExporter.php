<?php
/*
 * This file is part of Entity Management Bundle.
 *
 * (c) CJ Development Studios <contact@cjdevstudios.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CJDevStudios\EntityManagementBundle\Service;

use CJDevStudios\EntityManagementBundle\Entity\AbstractEntity;
use DateTime;
use Doctrine\Common\Collections\Collection;
use JMS\Serializer\SerializerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Service to handle exporting one or many entities
 * @since 1.0.0
 */
class EntityExporter {

    /**
     * CSV export format
     * @since 1.0.0
     * @var string
     */
    public const FORMAT_CSV = 'csv';

    /**
     * JSON export format
     * @since 1.0.0
     * @var string
     */
    public const FORMAT_JSON = 'json';

    /**
     * Excel export format
     * @since 1.0.0
     * @var string
     */
    public const FORMAT_EXCEL = 'xlsx';

    /**
     * Auto-wired Search Service
     * @since 1.0.0
     * @var Search
     */
    private $search;

    /**
     *
     * @since 1.0.0
     * @var LoggerInterface
     */
    private $logger;

    /**
     *
     * @since 1.0.0
     * @var SerializerInterface
     */
    private $serializer;

    /**
     *
     * @since 1.0.0
     * @var KernelInterface
     */
    private $kernel;

    /**
     * EntityExporter constructor.
     * @param Search $search
     * @param LoggerInterface $logger
     * @param SerializerInterface $serializer
     * @param KernelInterface $kernel
     */
    public function __construct(Search $search, LoggerInterface $logger, SerializerInterface $serializer, KernelInterface $kernel)
    {
        $this->search = $search;
        $this->logger = $logger;
        $this->serializer = $serializer;
        $this->kernel = $kernel;
    }

    /**
     * @since 1.0.0
     * @return array
     * @todo Needs to be extendable by plugins
     */
    public function getAllExportFormats(): array
    {
        return [self::FORMAT_CSV, self::FORMAT_JSON, self::FORMAT_EXCEL];
    }

    /**
     * @since 1.0.0
     * @param $format
     * @return string
     * @todo Needs to be extendable by plugins
     */
    public function getContentType(string $format): string
    {
        return match ($format) {
            self::FORMAT_CSV    => 'text/csv',
            self::FORMAT_JSON   => 'application/json',
            self::FORMAT_EXCEL  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default             => 'text/plain'
        };
    }

    public function exportByIds(string $entity_class, string $format, array $ids): string
    {
        //TODO Need to return more information about the final export so we can properly name the file in the controller
        return $this->exportByCriteria($entity_class, $format, ['id IN ' => $ids]);
    }

    public function exportByCriteria(string $entity_class, string $format, array $criteria, ?array $orderBy = null, $limit = null, $offset = null): string
    {
        //TODO Need to return more information about the final export so we can properly name the file in the controller
        if (!is_subclass_of($entity_class, AbstractEntity::class)) {
            $this->logger->error('Failed to export because ' . $entity_class . ' is not a valid entity');
            return '';
        }

        $items = $this->search->find($entity_class, $criteria, $orderBy, $limit, $offset);

        return match ($format) {
            self::FORMAT_CSV    => $this->exportCsv($items['fields'], $items['results']),
            self::FORMAT_JSON   => $this->exportJson($items['results']),
            self::FORMAT_EXCEL  => $this->exportExcel($items['fields'], $items['results']),
            default             => ''
        };
    }

    protected function exportCsv(array $fields, array $results): string
    {
        /** @var AbstractEntity $entity_class */
        $file = fopen('php://memory', 'wb+');

        $field_names = array_keys($fields);

        // Write headings
        fputcsv($file, $field_names);

        // Write content being sure to use the same field order
        foreach ($results as $result_class => $result_values) {
            $cols = [];
            foreach ($field_names as $field) {
                $col = $result_values->{'get' . $field}();
                if (is_array($col) || is_a($col, Collection::class)) {
                    continue;
                }
                if (is_a($col, DateTime::class)) {
                    /** @var DateTime $col */
                    $col = $col->format('Y-m-d H:i:s');
                }
                $cols[] = $col;
            }
            fputcsv($file, $cols);
        }

        rewind($file);
        $export = stream_get_contents($file);
        fclose($file);

        return $export;
    }

    protected function exportJson(array $results): string
    {
        return $this->serializer->serialize($results, 'json');
    }

    /**
     * Convert an x and y int coordinate to an Excel coordinate.
     * @since 1.0.0
     * @param int $x X value
     * @param int $y Y value
     * @return string Excel coordinate
     */
    private function getExcelCoordinate(int $x, int $y): string
    {
        $dividend = $x + 1;
        $columnName = '';

        while ($dividend > 0)
        {
            $modulo = ($dividend - 1) % 26;
            $columnName = chr(65 + $modulo) . $columnName;
            $dividend = (int) (($dividend - $modulo) / 26);
        }

        $c = $columnName;
        $c .= $y;

        return $c;
    }

    protected function exportExcel($fields, array $results): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $field_names = array_keys($fields);

        // Write header cells
        foreach ($field_names as $i => $iValue) {
            $sheet->setCellValue($this->getExcelCoordinate($i, 1), $iValue);
        }

        // Write content being sure to use the same field order
        $row_pointer = 2;
        foreach ($results as $result_class => $result_values) {
            $cols = [];
            foreach ($field_names as $field) {
                $col = $result_values->{'get' . $field}();
                if (is_array($col) || is_a($col, Collection::class)) {
                    continue;
                }
                if (is_a($col, DateTime::class)) {
                    /** @var DateTime $col */
                    $col = $col->format('Y-m-d H:i:s');
                }
                $cols[] = $col;
            }
            foreach ($cols as $i => $col) {
                $sheet->setCellValue($this->getExcelCoordinate($i, $row_pointer), $col);
            }
            $row_pointer++;
        }

        $writer = new Xlsx($spreadsheet);

        // Allocate a temporary file for PHPOffice
        $file = $this->kernel->getProjectDir() . '/var/temp/' . (md5(mt_rand())) . '.xlsx';
        // Save the spreadsheet to the temporary file
        $writer->save($file);

        // Open the temp file for reading only
        $handle = fopen($file, 'rb');
        // Read contents into a string
        $export = stream_get_contents($handle);
        // Close and then delete the temp file
        fclose($handle);
        unlink($file);

        // Return read contents
        return $export;
    }
}
