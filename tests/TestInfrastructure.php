<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube;

use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;

final class TestInfrastructure
{
    /** @var ?TestLogger Logger object used to store log messages produced during the tests */
    private static $logger;

    public static function init(): void
    {
        if (!isset(self::$logger)) {
            self::$logger = new TestLogger();
        }
    }

    public static function logger(): TestLogger
    {
        $logger = self::$logger;
        TestCase::assertNotNull($logger, "Call init() before asking for logger");
        return $logger;
    }

    public static function readJsonArray(string $jsonFile): array
    {
        TestCase::assertFileIsReadable($jsonFile);
        $json = file_get_contents($jsonFile);
        TestCase::assertNotFalse($json);
        $phpArray = json_decode($json, true);
        TestCase::assertTrue(is_array($phpArray));

        return $phpArray;
    }

    public static function readVCard(string $vcfFile): VCard
    {
        TestCase::assertFileIsReadable($vcfFile);
        $vcard = VObject\Reader::read(fopen($vcfFile, 'r'));
        TestCase::assertInstanceOf(VCard::class, $vcard);
        return $vcard;
    }

    /**
     * Reads a file at a path relative to a given base file and returns its content.
     *
     * @param string $path Relative path of the file to read.
     * @param string $baseFile File relative to whose path $path is to be interpreted to.
     */
    public static function readFileRelative(string $path, string $baseFile): string
    {
        $comp = pathinfo($baseFile);
        $file = "{$comp["dirname"]}/$path";
        TestCase::assertFileIsReadable($file);
        $content = file_get_contents($file);
        TestCase::assertIsString($content, "$file could not be read");
        return $content;
    }

    /**
     * Transforms a list of rows in associative array form to a list form.
     *
     * In other words, transforms the rows as returned by AbstractDatabase::get() to the format used internally.
     *
     * The function asserts that all fields listed in $cols actually have an entry in the provided associative rows.
     *
     * The order of rows is preserved.
     *
     * @param list<string> $cols The list of columns that defines fields and their order in the result rows.
     * @param list<array<string,?string>> $assocRows The associative row set where column names are keys.
     * @param bool $extraIsError If true, an assertion is done on that the associative rows contain now extra fields
     *                           that are not listed in $cols. If false, such fields will be discarded.
     * @return list<list<?string>>
     */
    public static function xformDatabaseResultToRowList(array $cols, array $assocRows, bool $extraIsError): array
    {
        $result = [];

        foreach ($assocRows as $aRow) {
            $lRow = [];

            foreach ($cols as $col) {
                TestCase::assertArrayHasKey($col, $aRow, "cols requires field not existing in associative row");
                $lRow[] = $aRow[$col];
                unset($aRow[$col]);
            }

            $result[] = $lRow;
            if ($extraIsError) {
                TestCase::assertEmpty($aRow, "Associative row has fields not listed in cols");
            }
        }

        return $result;
    }

    /**
     * Sorts a list of rows, where each row is a list of nullable strings.
     *
     * Fields are sorted alphabetically, in order of columns.
     *
     * @param list<list<?string>> $rowlist
     * @return list<list<?string>>
     */
    public static function sortRowList(array $rowlist): array
    {
        TestCase::assertTrue(usort($rowlist, [self::class, 'compareRows']));
        return $rowlist;
    }

    /**
     * Compare functions for two rows to use for sorting.
     *
     * @param list<?string> $r1
     * @param list<?string> $r2
     * @return int smaller/equal/greater 0 if $r1 is less/equal/greater than $r2
     *
     */
    public static function compareRows(array $r1, array $r2): int
    {
        $res = 0;

        TestCase::assertCount(count($r1), $r2, "compareRows requires rows of equal length");
        for ($i = 0; $res == 0 && $i < count($r1); ++$i) {
            $v1 = $r1[$i];
            $v2 = $r2[$i];

            if (isset($v1) && isset($v2)) {
                $res = strcmp($v1, $v2);
            } elseif (isset($v2)) {
                $res = -1;
            } elseif (isset($v1)) {
                $res = 1;
            }
        }

        return $res;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
