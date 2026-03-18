<?php
/**
 * MARC 21 XML Record Generator
 * Supports journals (bib level "s"), volumes (bib level "b"), and articles (bib level "a").
 * Requirements: composer require symfony/yaml
 */
require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// -----------------------------------------------------------------------
// MARC Data Classes
// -----------------------------------------------------------------------

class MarcControlField {
    public string $tag;
    public string $data;

    public function __construct(string $tag, string $data) {
        $this->tag  = $tag;
        $this->data = $data;
    }
}

class MarcField {
    public string $tag;
    public string $ind1;
    public string $ind2;
    public array  $subfields;

    public function __construct(string $tag, string $ind1 = ' ', string $ind2 = ' ', array $subfields = []) {
        $this->tag       = $tag;
        $this->ind1      = $ind1;
        $this->ind2      = $ind2;
        $this->subfields = $subfields;
    }
}

class MarcRecord {
    private string $leaderType;
    private array  $controlFields = [];
    private array  $dataFields    = [];

    public function __construct(string $type = 'journal') {
        $this->leaderType = $type;
    }

    public function addControlField(MarcControlField $field): void {
        $this->controlFields[] = $field;
    }

    public function addDataField(MarcField $field): void {
        $this->dataFields[] = $field;
    }

    public function toXml(DOMDocument $dom): DOMElement {
        $record   = $dom->createElement('record');
        if ($this->leaderType === 'article')     $bibLevel = 'a';
        elseif ($this->leaderType === 'volume')  $bibLevel = 'b';
        else                                     $bibLevel = 's';

        $leader = $dom->createElement('leader');
        $leader->appendChild($dom->createTextNode('00000na' . $bibLevel . ' a2200000 a 4500'));
        $record->appendChild($leader);

        foreach ($this->controlFields as $cf) {
            $node = $dom->createElement('controlfield');
            $node->setAttribute('tag', $cf->tag);
            $node->appendChild($dom->createTextNode($cf->data));
            $record->appendChild($node);
        }

        foreach ($this->dataFields as $df) {
            $node = $dom->createElement('datafield');
            $node->setAttribute('tag',  $df->tag);
            $node->setAttribute('ind1', $df->ind1);
            $node->setAttribute('ind2', $df->ind2);

            foreach ($df->subfields as $s) {
                $sub = $dom->createElement('subfield');
                $sub->setAttribute('code', $s['code']);
                $sub->appendChild($dom->createTextNode($s['data']));
                $node->appendChild($sub);
            }

            $record->appendChild($node);
        }

        return $record;
    }
}

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

/**
 * Add one 022 field per ISSN entry.
 * $issn may be:
 *   - a plain string:                   "2472-2901"
 *   - a list of objects with value/type: [{value: "2472-2901", type: online}, ...]
 */
function addIssnFields(MarcRecord $record, $issn): void {
    if (empty($issn)) return;
    if (is_string($issn)) {
        $record->addDataField(new MarcField('022', '0', ' ', [['code' => 'a', 'data' => $issn]]));
        return;
    }
    foreach ($issn as $entry) {
        $subs = [['code' => 'a', 'data' => $entry['value']]];
        if (!empty($entry['type'])) $subs[] = ['code' => '2', 'data' => $entry['type']];
        $record->addDataField(new MarcField('022', '0', ' ', $subs));
    }
}

// -----------------------------------------------------------------------
// Journal Record Builder
// -----------------------------------------------------------------------

function buildJournalMarcRecord(array $info): MarcRecord {
    $record = new MarcRecord('journal');

    if (!empty($info['control_number']))
        $record->addControlField(new MarcControlField('001', $info['control_number']));

    if (!empty($info['organization_code']))
        $record->addControlField(new MarcControlField('003', $info['organization_code']));

    $date      = date('ymd');
    $startYear = str_pad(substr($info['start_year'] ?? '    ', 0, 4), 4, ' ');
    $endYear   = str_pad(substr($info['end_year']   ?? '9999', 0, 4), 4, ' ');
    $freq      = $info['frequency_code'] ?? 'u';
    $field008  = $date . 'd' . $startYear . $endYear . $freq . '      ' . '         ' . ($info['language_code'] ?? 'eng') . '  ';
    $record->addControlField(new MarcControlField('008', $field008));

    addIssnFields($record, $info['issn'] ?? null);

    if (!empty($info['oclc']))
        $record->addDataField(new MarcField('035', ' ', ' ', [['code' => 'a', 'data' => '(OCoLC)' . $info['oclc']]]));

    $subs040 = [];
    if (!empty($info['organization_code'])) $subs040[] = ['code' => 'a', 'data' => $info['organization_code']];
    $subs040[] = ['code' => 'b', 'data' => $info['language_code'] ?? 'eng'];
    $subs040[] = ['code' => 'e', 'data' => 'rda'];
    if (!empty($info['organization_code'])) $subs040[] = ['code' => 'c', 'data' => $info['organization_code']];
    $record->addDataField(new MarcField('040', ' ', ' ', $subs040));

    if (!empty($info['geographic_area_code']))
        $record->addDataField(new MarcField('043', ' ', ' ', [['code' => 'a', 'data' => $info['geographic_area_code']]]));

    $titleSubs = [['code' => 'a', 'data' => rtrim($info['title'] ?? 'Untitled', ' .:,') . ' :']];
    if (!empty($info['subtitle'])) $titleSubs[] = ['code' => 'b', 'data' => $info['subtitle']];
    $record->addDataField(new MarcField('245', '0', '0', $titleSubs));

    if (!empty($info['abbreviated_title']))
        $record->addDataField(new MarcField('246', '1', ' ', [
            ['code' => 'i', 'data' => 'Abbreviated title'],
            ['code' => 'a', 'data' => $info['abbreviated_title']],
        ]));

    $pubSubs = [];
    if (!empty($info['place_of_publication'])) $pubSubs[] = ['code' => 'a', 'data' => $info['place_of_publication']];
    if (!empty($info['publisher']))             $pubSubs[] = ['code' => 'b', 'data' => $info['publisher']];
    if (!empty($info['start_year'])) {
        $d = $info['start_year'] . '-' . ($info['end_year'] ?? '');
        $pubSubs[] = ['code' => 'c', 'data' => $d];
    }
    if (!empty($pubSubs)) $record->addDataField(new MarcField('264', ' ', '1', $pubSubs));

    if (!empty($info['frequency']))
        $record->addDataField(new MarcField('310', ' ', ' ', [['code' => 'a', 'data' => $info['frequency']]]));

    $online = !empty($info['is_online']);
    $record->addDataField(new MarcField('336', ' ', ' ', [['code' => 'a', 'data' => 'text'], ['code' => 'b', 'data' => 'txt'], ['code' => '2', 'data' => 'rdacontent']]));
    $record->addDataField(new MarcField('337', ' ', ' ', [['code' => 'a', 'data' => $online ? 'computer' : 'unmediated'], ['code' => 'b', 'data' => $online ? 'c' : 'n'], ['code' => '2', 'data' => 'rdamedia']]));
    $record->addDataField(new MarcField('338', ' ', ' ', [['code' => 'a', 'data' => $online ? 'online resource' : 'volume'], ['code' => 'b', 'data' => $online ? 'cr' : 'nc'], ['code' => '2', 'data' => 'rdacarrier']]));

    if (!empty($info['first_issue']))
        $record->addDataField(new MarcField('362', '0', ' ', [['code' => 'a', 'data' => $info['first_issue']]]));

    if (!empty($info['subjects'])) {
        foreach ($info['subjects'] as $s)
            $record->addDataField(new MarcField('650', ' ', '0', [['code' => 'a', 'data' => $s]]));
    }

    if (!empty($info['publisher']))
        $record->addDataField(new MarcField('710', '2', ' ', [['code' => 'a', 'data' => $info['publisher']], ['code' => 'e', 'data' => 'publisher.']]));

    if (!empty($info['url']))
        $record->addDataField(new MarcField('856', '4', '0', [['code' => 'u', 'data' => $info['url']], ['code' => 'z', 'data' => 'Available online']]));

    return $record;
}

// -----------------------------------------------------------------------
// Article Record Builder
// -----------------------------------------------------------------------

function buildArticleMarcRecord(array $info): MarcRecord {
    $record = new MarcRecord('article');

    if (!empty($info['control_number']))
        $record->addControlField(new MarcControlField('001', $info['control_number']));

    if (!empty($info['organization_code']))
        $record->addControlField(new MarcControlField('003', $info['organization_code']));

    $date     = date('ymd');
    $year     = str_pad(substr($info['year'] ?? '    ', 0, 4), 4, ' ');
    $field008 = $date . 's' . $year . '         ' . '            ' . ($info['language_code'] ?? 'eng') . '  ';
    $record->addControlField(new MarcControlField('008', $field008));

    if (!empty($info['doi']))
        $record->addDataField(new MarcField('024', '7', ' ', [['code' => 'a', 'data' => $info['doi']], ['code' => '2', 'data' => 'doi']]));

    if (!empty($info['oclc']))
        $record->addDataField(new MarcField('035', ' ', ' ', [['code' => 'a', 'data' => '(OCoLC)' . $info['oclc']]]));

    $subs040 = [];
    if (!empty($info['organization_code'])) $subs040[] = ['code' => 'a', 'data' => $info['organization_code']];
    $subs040[] = ['code' => 'b', 'data' => $info['language_code'] ?? 'eng'];
    $subs040[] = ['code' => 'e', 'data' => 'rda'];
    if (!empty($info['organization_code'])) $subs040[] = ['code' => 'c', 'data' => $info['organization_code']];
    $record->addDataField(new MarcField('040', ' ', ' ', $subs040));

    if (!empty($info['author']))
        $record->addDataField(new MarcField('100', '1', ' ', [['code' => 'a', 'data' => $info['author']], ['code' => 'e', 'data' => 'author.']]));
    elseif (!empty($info['corporate_author']))
        $record->addDataField(new MarcField('110', '2', ' ', [['code' => 'a', 'data' => $info['corporate_author']], ['code' => 'e', 'data' => 'author.']]));

    $ind1 = (!empty($info['author']) || !empty($info['corporate_author'])) ? '1' : '0';
    $titleSubs = [['code' => 'a', 'data' => rtrim($info['title'] ?? 'Untitled', ' .:,')]];
    if (!empty($info['subtitle'])) {
        $titleSubs[0]['data'] .= ' :';
        $titleSubs[] = ['code' => 'b', 'data' => $info['subtitle']];
    }
    $record->addDataField(new MarcField('245', $ind1, '0', $titleSubs));

    $online = !empty($info['is_online']);
    $record->addDataField(new MarcField('336', ' ', ' ', [['code' => 'a', 'data' => 'text'], ['code' => 'b', 'data' => 'txt'], ['code' => '2', 'data' => 'rdacontent']]));
    $record->addDataField(new MarcField('337', ' ', ' ', [['code' => 'a', 'data' => $online ? 'computer' : 'unmediated'], ['code' => 'b', 'data' => $online ? 'c' : 'n'], ['code' => '2', 'data' => 'rdamedia']]));
    $record->addDataField(new MarcField('338', ' ', ' ', [['code' => 'a', 'data' => $online ? 'online resource' : 'volume'], ['code' => 'b', 'data' => $online ? 'cr' : 'nc'], ['code' => '2', 'data' => 'rdacarrier']]));

    if (!empty($info['notes'])) {
        foreach ((array)$info['notes'] as $note)
            $record->addDataField(new MarcField('500', ' ', ' ', [['code' => 'a', 'data' => $note]]));
    }

    if (!empty($info['abstract']))
        $record->addDataField(new MarcField('520', ' ', ' ', [['code' => 'a', 'data' => $info['abstract']]]));

    if (!empty($info['subjects'])) {
        foreach ($info['subjects'] as $s)
            $record->addDataField(new MarcField('650', ' ', '0', [['code' => 'a', 'data' => $s]]));
    }

    if (!empty($info['additional_authors'])) {
        foreach ($info['additional_authors'] as $co)
            $record->addDataField(new MarcField('700', '1', ' ', [['code' => 'a', 'data' => $co], ['code' => 'e', 'data' => 'author.']]));
    }

    if (!empty($info['host_journal'])) {
        $host = $info['host_journal'];
        $subs = [];
        if (!empty($host['title']))         $subs[] = ['code' => 't', 'data' => $host['title']];
        if (!empty($host['issn']))           $subs[] = ['code' => 'x', 'data' => $host['issn']];
        if (!empty($host['publisher']))      $subs[] = ['code' => 'd', 'data' => $host['publisher']];
        $g = buildRelatedParts($host);
        if ($g !== '')                       $subs[] = ['code' => 'g', 'data' => $g];
        if (!empty($host['control_number'])) $subs[] = ['code' => 'w', 'data' => $host['control_number']];
        if (!empty($subs)) $record->addDataField(new MarcField('773', '0', '8', $subs));
    }

    if (!empty($info['url']))
        $record->addDataField(new MarcField('856', '4', '0', [['code' => 'u', 'data' => $info['url']], ['code' => 'z', 'data' => 'Available online']]));

    return $record;
}

// -----------------------------------------------------------------------
// Volume Record Builder
// -----------------------------------------------------------------------

function buildVolumeMarcRecord(array $info): MarcRecord {
    $record = new MarcRecord('volume');

    if (!empty($info['control_number']))
        $record->addControlField(new MarcControlField('001', $info['control_number']));

    if (!empty($info['organization_code']))
        $record->addControlField(new MarcControlField('003', $info['organization_code']));

    $date     = date('ymd');
    $year     = str_pad(substr($info['year'] ?? '    ', 0, 4), 4, ' ');
    $field008 = $date . 's' . $year . '         ' . '            ' . ($info['language_code'] ?? 'eng') . '  ';
    $record->addControlField(new MarcControlField('008', $field008));

    addIssnFields($record, $info['issn'] ?? null);

    if (!empty($info['oclc']))
        $record->addDataField(new MarcField('035', ' ', ' ', [['code' => 'a', 'data' => '(OCoLC)' . $info['oclc']]]));

    $subs040 = [];
    if (!empty($info['organization_code'])) $subs040[] = ['code' => 'a', 'data' => $info['organization_code']];
    $subs040[] = ['code' => 'b', 'data' => $info['language_code'] ?? 'eng'];
    $subs040[] = ['code' => 'e', 'data' => 'rda'];
    if (!empty($info['organization_code'])) $subs040[] = ['code' => 'c', 'data' => $info['organization_code']];
    $record->addDataField(new MarcField('040', ' ', ' ', $subs040));

    $titleSubs = [['code' => 'a', 'data' => rtrim($info['title'] ?? 'Untitled', ' .:,')]];
    if (!empty($info['subtitle'])) {
        $titleSubs[0]['data'] .= ' :';
        $titleSubs[] = ['code' => 'b', 'data' => $info['subtitle']];
    }
    $record->addDataField(new MarcField('245', '0', '0', $titleSubs));

    $pubSubs = [];
    if (!empty($info['place_of_publication'])) $pubSubs[] = ['code' => 'a', 'data' => $info['place_of_publication']];
    if (!empty($info['publisher']))             $pubSubs[] = ['code' => 'b', 'data' => $info['publisher']];
    if (!empty($info['year']))                  $pubSubs[] = ['code' => 'c', 'data' => $info['year']];
    if (!empty($pubSubs)) $record->addDataField(new MarcField('264', ' ', '1', $pubSubs));

    $online = !empty($info['is_online']);
    $record->addDataField(new MarcField('336', ' ', ' ', [['code' => 'a', 'data' => 'text'], ['code' => 'b', 'data' => 'txt'], ['code' => '2', 'data' => 'rdacontent']]));
    $record->addDataField(new MarcField('337', ' ', ' ', [['code' => 'a', 'data' => $online ? 'computer' : 'unmediated'], ['code' => 'b', 'data' => $online ? 'c' : 'n'], ['code' => '2', 'data' => 'rdamedia']]));
    $record->addDataField(new MarcField('338', ' ', ' ', [['code' => 'a', 'data' => $online ? 'online resource' : 'volume'], ['code' => 'b', 'data' => $online ? 'cr' : 'nc'], ['code' => '2', 'data' => 'rdacarrier']]));

    // 362 — volume/issue designation
    if (!empty($info['volume'])) {
        $desig = 'Vol. ' . $info['volume'];
        if (!empty($info['issue_range'])) $desig .= ', no. ' . $info['issue_range'];
        $dp = [];
        if (!empty($info['date_range'])) $dp[] = $info['date_range'];
        elseif (!empty($info['year']))   $dp[] = $info['year'];
        if (!empty($dp)) $desig .= ' (' . implode(' ', $dp) . ')';
        $record->addDataField(new MarcField('362', '0', ' ', [['code' => 'a', 'data' => $desig]]));
    }

    if (!empty($info['notes'])) {
        foreach ((array)$info['notes'] as $note)
            $record->addDataField(new MarcField('500', ' ', ' ', [['code' => 'a', 'data' => $note]]));
    }

    if (!empty($info['subjects'])) {
        foreach ($info['subjects'] as $s)
            $record->addDataField(new MarcField('650', ' ', '0', [['code' => 'a', 'data' => $s]]));
    }

    if (!empty($info['editors'])) {
        foreach ($info['editors'] as $ed)
            $record->addDataField(new MarcField('700', '1', ' ', [['code' => 'a', 'data' => $ed], ['code' => 'e', 'data' => 'editor.']]));
    }

    if (!empty($info['publisher']))
        $record->addDataField(new MarcField('710', '2', ' ', [['code' => 'a', 'data' => $info['publisher']], ['code' => 'e', 'data' => 'publisher.']]));

    // 773 — host item (parent journal)
    if (!empty($info['host_journal'])) {
        $host = $info['host_journal'];
        $subs = [];
        if (!empty($host['title']))          $subs[] = ['code' => 't', 'data' => $host['title']];
        if (!empty($host['issn']))           $subs[] = ['code' => 'x', 'data' => $host['issn']];
        if (!empty($host['control_number'])) $subs[] = ['code' => 'w', 'data' => $host['control_number']];
        if (!empty($subs)) $record->addDataField(new MarcField('773', '0', '8', $subs));
    }

    if (!empty($info['url']))
        $record->addDataField(new MarcField('856', '4', '0', [['code' => 'u', 'data' => $info['url']], ['code' => 'z', 'data' => 'Available online']]));

    return $record;
}

function buildRelatedParts(array $host): string {
    $parts = [];
    if (!empty($host['volume'])) $parts[] = 'Vol. ' . $host['volume'];
    if (!empty($host['issue']))  $parts[] = 'no. '  . $host['issue'];
    $dp = [];
    if (!empty($host['season'])) $dp[] = $host['season'];
    if (!empty($host['year']))   $dp[] = $host['year'];
    if (!empty($dp)) $parts[] = '(' . implode(' ', $dp) . ')';
    if (!empty($host['pages']))  $parts[] = 'p. ' . $host['pages'];
    return implode(', ', $parts);
}

// -----------------------------------------------------------------------
// YAML Input & XML Output
// -----------------------------------------------------------------------

function loadRecordsFromYaml(string $path): array {
    if (!file_exists($path))
        throw new RuntimeException("YAML file not found: $path");

    $parsed   = Yaml::parseFile($path);
    $journals = $parsed['journals'] ?? [];
    $volumes  = $parsed['volumes']  ?? [];
    $articles = $parsed['articles'] ?? [];

    if (empty($journals) && empty($volumes) && empty($articles)) {
        if (isset($parsed['title']) && !isset($parsed['host_journal']))      $journals = [$parsed];
        elseif (isset($parsed['title']) && isset($parsed['host_journal']))   $articles = [$parsed];
        else throw new RuntimeException("Unrecognised YAML structure.");
    }

    return ['journals' => $journals, 'volumes' => $volumes, 'articles' => $articles];
}

function generateMarcXml(array $journals, array $volumes, array $articles, string $outputPath): void {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $col = $dom->createElement('collection');
    $col->setAttribute('xmlns', 'http://www.loc.gov/MARC21/slim');
    $col->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $col->setAttribute('xsi:schemaLocation',
        'http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd');
    $dom->appendChild($col);

    foreach ($journals as $info) $col->appendChild(buildJournalMarcRecord($info)->toXml($dom));
    foreach ($volumes  as $info) $col->appendChild(buildVolumeMarcRecord($info)->toXml($dom));
    foreach ($articles as $info) $col->appendChild(buildArticleMarcRecord($info)->toXml($dom));

    if ($dom->save($outputPath) === false)
        throw new RuntimeException("Failed to write XML to: $outputPath");
}

// -----------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------

$yamlPath   = $argv[1] ?? __DIR__ . '/records.yaml';
$outputPath = $argv[2] ?? __DIR__ . '/output.xml';

try {
    ['journals' => $journals, 'volumes' => $volumes, 'articles' => $articles] = loadRecordsFromYaml($yamlPath);
    generateMarcXml($journals, $volumes, $articles, $outputPath);
    $total = count($journals) + count($volumes) + count($articles);
    echo "MARCXML written to: $outputPath\n";
    echo "  Journals : " . count($journals) . "\n";
    echo "  Volumes  : " . count($volumes)  . "\n";
    echo "  Articles : " . count($articles) . "\n";
    echo "  Total    : $total record(s)\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
