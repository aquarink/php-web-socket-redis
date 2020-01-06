<?php

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
// use MyApp\Socket;
use Predis\Client;

require dirname( __FILE__ ) . '/vendor/autoload.php';
// require dirname( __FILE__ ) . '/Socket.php';

class Socket implements MessageComponentInterface {

    private $clients = [];

    public function __construct()
    {
        // $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {

        // QUERY STRING BUT NO PARAM
        $querystring = $conn->httpRequest->getUri()->getQuery();

        // STORE NEW CLIENT
        $this->clients[$conn->resourceId] = $conn;

        // ROOM ID & PLAYER ID & NAME PLAYER & AVATAR URL
        $qs = explode('&', $querystring);

        if($querystring == '') {
            // PUSH
            $this->clients[$conn->resourceId]->send('GM : Parameters not found');
        } else {
            if(count($qs) == 3) {

                $dataQs = array(
                    'roomId'        => $qs[0],
                    'playerId'      => $qs[1],
                    'playerName'    => urldecode($qs[2])
                );

                $redis = new Client();
                // INIT ROOM
                $redis->hset('ROOMS:'.$dataQs['roomId'], $conn->resourceId, $dataQs['playerId'].':'.$dataQs['playerName']);

                // INIT PLAYER BIND ROOM
                $redis->set('PLAYERS:'.$conn->resourceId, $dataQs['roomId']);

                // echo "Player List on Room ".$dataQs['roomId']." : \n";
                // echo "KEY List on Room ".$dataQs['roomId']." : \n";
                // $getRoomKeyData = $redis->hkeys('ROOMS:'.$dataQs['roomId']);
                // print_r($getRoomKeyData);

                // echo "VAL List on Room ".$dataQs['roomId']." : \n";
                // $getRoomValData = $redis->hvals('ROOMS:'.$dataQs['roomId']);
                // print_r($getRoomValData);

                $this->clients[$conn->resourceId]->send('GM : Connected');

                // BROADCAST
                // DATA PLAYER IN ROOM
                $getRoomKeyData = $redis->hkeys('ROOMS:'.$dataQs['roomId']);
                // print_r($getRoomKeyData);
                foreach ($getRoomKeyData as $key => $resourceId) {
                    if(isset($this->clients[$resourceId])) {
                        $this->clients[$resourceId]->send("GM : [".$dataQs['playerName']."] connected \n");
                    }
                }
            } else {
                // PUSH
                $this->clients[$conn->resourceId]->send('GM : Parameters not found'); 
            }
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {

        $redis = new Client();
        // GET DATA FROM PLAYES
        $playerData = $redis->get('PLAYERS:'.$from->resourceId);

        // GET DATA ROOMS FROM DATA PLAYERS
        $getPlayerData = $redis->hget('ROOMS:'.$playerData, $from->resourceId);

        // EXPLODE ROOM OUTPUT
        $expl_player = explode(':', $getPlayerData);

        // DATA PLAYER IN ROOM
        $getRoomKeyData = $redis->hkeys('ROOMS:'.$playerData);

        // print_r($getRoomKeyData);

        // BROADCAST
        foreach ($getRoomKeyData as $key => $resourceId) {
            if(isset($this->clients[$resourceId])) {
                $this->clients[$resourceId]->send("[".$expl_player[1]."] : ".$msg." \n");
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {

        $redis = new Client();
        // GET DATA FROM PLAYES
        $playerData = $redis->get('PLAYERS:'.$conn->resourceId);

        // GET DATA ROOMS FROM DATA PLAYERS
        $getPlayerData = $redis->hget('ROOMS:'.$playerData, $conn->resourceId);

        // DATA PLAYER IN ROOM
        $getRoomKeyData = $redis->hkeys('ROOMS:'.$playerData);

        // print_r($getRoomKeyData);

        // EXPLODE ROOM OUTPUT
        $expl_player = explode(':', $getPlayerData);

        // LOG
        echo "[".$conn->resourceId."] : Player ID ".$expl_player[0]." is ".$expl_player[1]." disconnect\n";
        
        // DELETE DATA FROM PLAYERS
        $redis->del('PLAYERS:'.$conn->resourceId);

        // DELETE DATA FROM ROOMS
        $redis->hdel('ROOMS:'.$playerData, $conn->resourceId);

        // BROADCAST
        foreach ($getRoomKeyData as $key => $resourceId) {
            if(isset($this->clients[$resourceId])) {
                $this->clients[$resourceId]->send("GM : Player ".$expl_player[1]." disconnected");
            }
        }        
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error Connection ({$conn->resourceId}) : {$e} \n";
        $conn->close();
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Socket()
        )
    ),
    8081
);

echo "Socket On \n";
$server->run();

