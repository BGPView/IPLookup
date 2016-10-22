<?php

namespace App\Console\Commands;

use Elasticsearch\ClientBuilder;
use Illuminate\Console\Command;

class ReindexArinWhois extends ReindexRIRWhois
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zBGPView:reindex-arin-whois';
    protected $indexName = 'arin_whois_db';
    protected $versionedIndex;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindex in ES all the RIR raw whois data';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->versionedIndex = $this->indexName . '_' . time();

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $params = [
            'index' => $this->versionedIndex,
            'body' => $this->getIndexMapping(),
        ];
        $this->esClient->indices()->create($params);

        $this->processAsns();
        $this->processPocs();
        $this->processOrgs();
        $this->processNets();

        $this->hotSwapIndices($this->versionedIndex, $this->indexName);
    }

    private function processPocs()
    {
        $this->bench->start();
        $currentCount = 0;

        $this->info('Reading ARIN POC whois file');
        $url = 'https://www.arin.net/public/secure/downloads/bulkwhois/pocs.txt';
        $rawContent = $this->getContents($url);
        // Split all block
        $whoisBlocks = explode("\n\n\n\n", $rawContent);
        $this->info(number_format(count($whoisBlocks)) . ' POC Blocks Found');

        foreach ($whoisBlocks as $whoisBlock) {
            // Do quick cleanup
            $whoisBlock = trim($whoisBlock);
            $pocId = $this->extractValues($whoisBlock, 'POCHandle');
            if (empty($pocId) === true) {
                $this->warn('-------------------');
                $this->warn('Unknown POC on:');
                $this->info($whoisBlock);
                $this->warn('-------------------');
                continue;
            }

            $data = [
                'poc_id' => $pocId,
                'whois_block' => $whoisBlock,
            ];

            $params['body'][] = [
                'index' => [
                    '_index' => $this->versionedIndex,
                    '_type' => 'pocs',
                ]
            ];
            $params['body'][] = $data;
            $currentCount++;

            if ($currentCount > $this->esBatchAmount) {
                $this->info('Inserted ' . number_format($this->esBatchAmount) . ' POC records');
                // Get our document body data.
                $this->esClient->bulk($params);
                // Reset the batching
                $currentCount = 0;
                $params['body'] = [];
            }

        }

        // Insert the remaining entries
        if (count($params['body']) > 0) {
            $this->esClient->bulk($params);
            $this->info('Inserted the remaining ' . count($params['body']) . ' records');
        }

        $this->bench->end();
        $this->info(sprintf(
            'Time: %s, Memory: %s',
            $this->bench->getTime(),
            $this->bench->getMemoryPeak()
        ));
    }

    private function processOrgs()
    {
        $this->bench->start();
        $currentCount = 0;

        $this->info('Reading ARIN ORG whois file');
        $url = 'https://www.arin.net/public/secure/downloads/bulkwhois/orgs.txt';
        $rawContent = $this->getContents($url);
        // Split all block
        $whoisBlocks = explode("\n\n\n\n", $rawContent);
        $this->info(number_format(count($whoisBlocks)) . ' ORG Blocks Found');

        foreach ($whoisBlocks as $whoisBlock) {
            // Do quick cleanup
            $whoisBlock = trim($whoisBlock);
            $orgId = $this->extractValues($whoisBlock, 'OrgID');
            if (empty($orgId) === true) {
                $this->warn('-------------------');
                $this->warn('Unknown ORG on:');
                $this->info($whoisBlock);
                $this->warn('-------------------');
                continue;
            }

            $data = [
                'org_id' => $orgId,
                'whois_block' => $whoisBlock,
            ];

            $params['body'][] = [
                'index' => [
                    '_index' => $this->versionedIndex,
                    '_type' => 'orgs',
                ]
            ];
            $params['body'][] = $data;
            $currentCount++;

            if ($currentCount > $this->esBatchAmount) {
                $this->info('Inserted ' . number_format($this->esBatchAmount) . ' ORG records');
                // Get our document body data.
                $this->esClient->bulk($params);
                // Reset the batching
                $currentCount = 0;
                $params['body'] = [];
            }

        }

        // Insert the remaining entries
        if (count($params['body']) > 0) {
            $this->esClient->bulk($params);
            $this->info('Inserted the remaining ' . count($params['body']) . ' records');
        }

        $this->bench->end();
        $this->info(sprintf(
            'Time: %s, Memory: %s',
            $this->bench->getTime(),
            $this->bench->getMemoryPeak()
        ));
    }

    private function processNets()
    {
        $this->bench->start();
        $currentCount = 0;

        $this->info('Reading ARIN Prefix whois file');
        $url = 'https://www.arin.net/public/secure/downloads/bulkwhois/nets.txt';
        $rawContent = $this->getContents($url);

        // Split all block
        $whoisBlocks = explode("\n\n\n", $rawContent);
        $this->info(number_format(count($whoisBlocks)) . ' NET Blocks Found');

        foreach ($whoisBlocks as $whoisBlock) {
            // Do quick cleanup
            $whoisBlock = trim($whoisBlock);
            $netRange = $this->extractValues($whoisBlock, 'NetRange');
            if (empty($netRange) === true) {
                $this->warn('-------------------');
                $this->warn('Unknown NetRange on:');
                $this->info($whoisBlock);
                $this->warn('-------------------');
                continue;
            }
            $netRangeParts = explode(' - ', $netRange);
            $ipDecStart = $this->ipUtils->ip2dec($netRangeParts[0]);
            $ipDecEnd = $this->ipUtils->ip2dec($netRangeParts[1]);

            $data = [
                'ip_dec_start' => $ipDecStart,
                'ip_dec_end' => $ipDecEnd,
                'ip_count' => bcadd(1, bcsub($ipDecEnd, $ipDecStart)),
                'whois_block' => $whoisBlock,
            ];

            $params['body'][] = [
                'index' => [
                    '_index' => $this->versionedIndex,
                    '_type' => 'nets',
                ]
            ];
            $params['body'][] = $data;
            $currentCount++;

            if ($currentCount > $this->esBatchAmount) {
                $this->info('Inserted ' . number_format($this->esBatchAmount) . ' NET records');
                // Get our document body data.
                $this->esClient->bulk($params);
                // Reset the batching
                $currentCount = 0;
                $params['body'] = [];
            }
        }

        // Insert the remaining entries
        if (count($params['body']) > 0) {
            $this->esClient->bulk($params);
            $this->info('Inserted the remaining ' . count($params['body']) . ' records');
        }

        $this->bench->end();
        $this->info(sprintf(
            'Time: %s, Memory: %s',
            $this->bench->getTime(),
            $this->bench->getMemoryPeak()
        ));
    }

    private function processAsns()
    {
        $this->bench->start();
        $currentCount = 0;

        $this->info('Reading ARIN ASN whois file');
        $url = 'https://www.arin.net/public/secure/downloads/bulkwhois/asns.txt';
        $rawContent = $this->getContents($url);
        // Split all block
        $whoisBlocks = explode("\n\n\n\n", $rawContent);
        $this->info(number_format(count($whoisBlocks)) . ' ASN Blocks Found');

        foreach ($whoisBlocks as $whoisBlock) {
            // Do quick cleanup
            $whoisBlock = trim($whoisBlock);
            $orgId = $this->extractValues($whoisBlock, 'OrgID');
            $asns = $this->extractValues($whoisBlock, 'ASNumber');
            if (empty($asns) === true && $asns !== "0") {
                $this->warn('-------------------');
                $this->warn('Unknown ASN on:');
                $this->info($whoisBlock);
                $this->warn('-------------------');
                continue;
            }
            // Detect if the ASN is a block rather than a single ASN
            if (is_numeric($asns) === true) {
                $asnStart = $asns;
                $asnEnd = $asns;
            } else {
                $asnParts = explode(" - ", $asns);
                $asnStart = trim($asnParts[0]);
                $asnEnd = trim($asnParts[1]);
            }

            $data = [
                'asn_start' => $asnStart,
                'asn_end' => $asnEnd,
                'org_id' => $orgId,
                'whois_block' => $whoisBlock,
            ];

            $params['body'][] = [
                'index' => [
                    '_index' => $this->versionedIndex,
                    '_type' => 'asns',
                ]
            ];
            $params['body'][] = $data;
            $currentCount++;

            if ($currentCount > $this->esBatchAmount) {
                $this->info('Inserted ' . number_format($this->esBatchAmount) . ' ASN records');
                // Get our document body data.
                $this->esClient->bulk($params);
                // Reset the batching
                $currentCount = 0;
                $params['body'] = [];
            }

        }

        // Insert the remaining entries
        if (count($params['body']) > 0) {
            $this->esClient->bulk($params);
            $this->info('Inserted the remaining ' . count($params['body']) . ' records');
        }

        $this->bench->end();
        $this->info(sprintf(
            'Time: %s, Memory: %s',
            $this->bench->getTime(),
            $this->bench->getMemoryPeak()
        ));
    }

    /**
     * Return index mapping.
     */
    public function getIndexMapping()
    {
        return [
            'mappings' => [
                'asns'  => [
                    'properties' => [
                        'asn_start'    => ['type' => 'integer', 'index' => 'not_analyzed'],
                        'asn_end'    => ['type' => 'integer', 'index' => 'not_analyzed'],
                        'org_id'    => ['type' => 'string', 'index' => 'not_analyzed'],
                        'whois_block'    => ['type' => 'string', 'index' => 'not_analyzed'],
                    ],
                ],
                'nets'  => [
                    'properties' => [
                        'ip_dec_start'    => ['type' => 'double', 'index' => 'not_analyzed'],
                        'ip_dec_end'    => ['type' => 'double', 'index' => 'not_analyzed'],
                        'ip_count'    => ['type' => 'double', 'index' => 'not_analyzed'],
                        'whois_block'    => ['type' => 'string', 'index' => 'not_analyzed'],
                    ],
                ],
                'pocs'  => [
                    'properties' => [
                        'poc_id'    => ['type' => 'string', 'index' => 'not_analyzed'],
                        'whois_block'    => ['type' => 'string', 'index' => 'not_analyzed'],
                    ],
                ],
                'pocs'  => [
                    'properties' => [
                        'poc_id'    => ['type' => 'string', 'index' => 'not_analyzed'],
                        'whois_block'    => ['type' => 'string', 'index' => 'not_analyzed'],
                    ],
                ],
                'orgs'  => [
                    'properties' => [
                        'org_id'    => ['type' => 'string', 'index' => 'not_analyzed'],
                        'whois_block'    => ['type' => 'string', 'index' => 'not_analyzed'],
                    ],
                ],
            ],
        ];
    }

}