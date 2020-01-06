<?php

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Predis\Client;

require dirname( __FILE__ ) . '/vendor/autoload.php';

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
                        $this->clients[$resourceId]->send("[BUTTON_PLAY] : TRUE \n");
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

        // INIT
        $chat = '';
        $play = false;

        // PARSE PESAN
        $expl_mgg = explode('***', $msg);
        if($expl_mgg[0] == 'chat') {
            $chat = "[".$expl_player[1]."] : ".$expl_mgg[1]." \n";
        } elseif($expl_mgg[0] == 'play') {

            if($expl_mgg[1] == 'start') {
                // GET DATA FROM PLAY
                $playData = $redis->get('PLAY:'.$playerData);
                if($playData == '') {
                    // // INIT PLAY THE GAME BIND ROOM
                    $redis->set('PLAY:'.$playerData, $from->resourceId);
                    $play = "[BUTTON_PLAY] : FALSE ".$expl_player[1]." is Playing \n";
                } else {
                    // GET DATA PLAY ROOM DATA
                    $getPlayRoomData = $redis->hget('ROOMS:'.$playerData, $playData);
                    // EXPLODE PLAY DATA
                    $expl_play = explode(':', $getPlayRoomData);
                    $this->clients[$from->resourceId]->send("[BUTTON_PLAY] : FALSE ".$expl_play[1]." is Playing \n");
                }
            } elseif($expl_mgg[1] == 'end') {
                // GET DATA FROM PLAY
                $playData = $redis->get('PLAY:'.$playerData);
                if($playData == $from->resourceId) {
                    $redis->set('PLAY:'.$playerData, '');
                    $play = "[BUTTON_PLAY] : TRUE please push \n";
                } else {
                    // GET DATA PLAY ROOM DATA
                    $getPlayRoomData = $redis->hget('ROOMS:'.$playerData, $playData);
                    // EXPLODE PLAY DATA
                    $expl_play = explode(':', $getPlayRoomData);
                    $play = "[BUTTON_PLAY] : FALSE ".$expl_play[1]." is Playing please wait \n";
                }
            } else {
                $play = "Perintah bermain salah \n";
            }           
        }

        // BROADCAST
        foreach ($getRoomKeyData as $key => $resourceId) {
            if(isset($this->clients[$resourceId])) {
                if($chat != '') {
                    $this->clients[$resourceId]->send($chat);
                } elseif($play != '') {
                    $this->clients[$resourceId]->send($play);
                }
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

