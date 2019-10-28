<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\RequestException;

use App\CrawledNode;
use App\CachedNode;

use App\Jobs\CleanUpCachedNodes;

use DB;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->call(function () {

            $client = new GuzzleHttpClient();
            $requestContent = [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    "size" => 1000,
                    "query" => [
                        "range" => [
                            "ts" => [
                                "gte" => date('Y-m-d H:i:s', strtotime('-15 minutes')),
                                "lt" => date('Y-m-d H:i:s', strtotime('-10 minutes')),
                                "format" => "yyyy-MM-dd HH:mm:ss"
                            ],
                        ],
                    ],
                    "_source" => [
                        "includes" => ["ip", "version", "publicKey", "id"]
                    ]
                ]
            ];

            $apiRequest = $client->Get(config('elasticsearch.elastic_search_url').date('Y-m-d').'*/_search?scroll=5m',$requestContent);

            $response = json_decode($apiRequest->getBody(), true);

            if(array_key_exists("_scroll_id",$response)){
                $scrollID = $response["_scroll_id"];

                $insertStrings = array();

                $requestContent = [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json'
                    ],
                    'json' => [
                        "scroll" => "5m",
                        "scroll_id" => $scrollID,
                    ]
                ];

                $i = 1;

                while($response["hits"]["hits"]){
                    foreach ($response["hits"]["hits"] as $node){

                        //look up the cache
                        $cachedNode = CachedNode::where('ip', $node["_source"]["ip"])->first();
                        //if node is cached get it
                        if($cachedNode){
                            $response = $cachedNode->toArray();
                            $cachedNode->touch();
                        }
                        //if not ask the api
                        else{
                            $client = new GuzzleHttpClient();
                            $apiRequest = $client->Get('https://api.ipgeolocation.io/ipgeo?apiKey='.config('geolocation.ipgeolocation_key').'&ip='.$node["_source"]["ip"]);
                            $response = json_decode($apiRequest->getBody(), true);
                            //update or create cache entry
                            $dbCachedNode = CachedNode::firstOrCreate(array('ip' => $node["_source"]["ip"]));
                            $dbCachedNode->fill($response);
                            $dbCachedNode->save();
                            $response = $dbCachedNode->toArray();
                        }

                        $insertString = $i
                            . "\t" . $node["_source"]["ip"]
                            . "\t" . $response["continent_code"]
                            . "\t" . $response["country_code2"]
                            . "\t" . $response["city"]
                            . "\t" . $response["latitude"]
                            . "\t" . $response["longitude"]
                            . "\t" . $response["isp"]
                            . "\t" . $response["organization"]
                            . "\t" . $node["_source"]["publicKey"]
                            . "\t" . $node["_source"]["version"]
                            . "\t" . date('Y-m-d H:i:s')
                            . "\t" . date('Y-m-d H:i:s')
                            . "\n";

                        array_push($insertStrings,$insertString);
                        $i++;

                    }
                    //fetch next set
                    $apiRequest = $client->Get('https://search-nkn-testnet-457rbvoxco6zwq4uaebeoolm4u.us-east-2.es.amazonaws.com/_search/scroll',$requestContent);
                    $response = json_decode($apiRequest->getBody(), true);
                }
                if($i > 100){
                    $host        = "host=" . config('database.connections.pgsql2.host');
                    $port        = "port=" . config('database.connections.pgsql2.port');
                    $dbname      = "dbname=" . config('database.connections.pgsql2.database');
                    $dbuser      = "user=" . config('database.connections.pgsql2.username');
                    $dbpass      = "password=" . config('database.connections.pgsql2.password');

                    $db = pg_connect( "$host $port $dbname $dbuser $dbpass"  );

                    CrawledNode::truncate();
                    pg_copy_from($db,"crawled_nodes",$insertStrings);
                }
            }
        })->everyFiveMinutes()->name('CrawlNodes')->withoutOverlapping()->appendOutputTo(storage_path('logs/CrawlNodes.log'));

        $schedule->call(function () {
            CleanUpCachedNodes::dispatch()->onQueue('maintenance');
        })->monthly()->name('CleanUpCachedNodes')->withoutOverlapping();
    }


    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
