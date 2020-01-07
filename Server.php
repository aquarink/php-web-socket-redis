<?php

// use Ratchet\Client as RClient;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Predis\Client;
use WebSocket\Client as WSClient;

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
            $this->clients[$conn->resourceId]->send('pesan**Parameters not found');
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

                $this->clients[$conn->resourceId]->send('pesan**Terkoneksi');
                $this->clients[$conn->resourceId]->send('pesan**ID : '.$conn->resourceId);

                // BROADCAST
                // DATA PLAYER IN ROOM
                $getRoomKeyData = $redis->hkeys('ROOMS:'.$dataQs['roomId']);
                // print_r($getRoomKeyData);
                foreach ($getRoomKeyData as $key => $resourceId) {
                    if(isset($this->clients[$resourceId])) {
                        $this->clients[$resourceId]->send("pesan**".$dataQs['playerName']." connected \n");
                        $this->clients[$resourceId]->send("button_play**true");
                    }
                }
            } else {
                // PUSH
                $this->clients[$conn->resourceId]->send("pesan**Parameters string not found \n"); 
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
        $play = '';
        $playSelf = '';
        $playOther = '';

        // PARSE PESAN
        $expl_mgg = explode('***', $msg);
        if($expl_mgg[0] == 'chat') {
            $chatOther = "pesan**".$expl_player[1]." : ".$expl_mgg[1]." \n";

            // PUBLIC
            // BROADCAST
            foreach ($getRoomKeyData as $key => $resourceId) {
                if(isset($this->clients[$resourceId])) {
                    $this->clients[$resourceId]->send($chatOther);
                }
            }
        } elseif($expl_mgg[0] == 'play') {

            if($expl_mgg[1] == 'start') {
                // GET DATA FROM PLAY
                $playData = $redis->get('PLAY:'.$playerData);
                if($playData == '') {
                    // // INIT PLAY THE GAME BIND ROOM
                    $redis->set('PLAY:'.$playerData, $from->resourceId);
                    $playSelf = "button_play_self**true";
                    $playOther = "button_play**false**".$expl_player[1]." is Playing";

                    // PUBLIC
                    // SELF
                    $this->clients[$from->resourceId]->send($playSelf);
                    // BROADCAST
                    foreach ($getRoomKeyData as $key => $resourceId) {
                        if(isset($this->clients[$resourceId])) {
                            $playData = $redis->get('PLAY:'.$playerData);

                            if($resourceId != $playData) {
                                $this->clients[$resourceId]->send($playOther);
                            }
                        }
                    }
                } else {
                    // GET DATA PLAY ROOM DATA
                    $getPlayRoomData = $redis->hget('ROOMS:'.$playerData, $playData);
                    // EXPLODE PLAY DATA
                    $expl_play = explode(':', $getPlayRoomData);

                    $playSelf = "button_play_self**true";
                    $playOther = "button_play**false**".$expl_play[1]." is Playing";

                    // PUBLIC
                    // SELF
                    $this->clients[$from->resourceId]->send($playSelf);
                    // BROADCAST
                    foreach ($getRoomKeyData as $key => $resourceId) {
                        if(isset($this->clients[$resourceId])) {
                            $playData = $redis->get('PLAY:'.$playerData);

                            if($resourceId != $playData) {
                                $this->clients[$resourceId]->send($playOther);
                            }
                        }
                    }
                }
            } elseif($expl_mgg[1] == 'end') {
                // GET DATA FROM PLAY
                $playData = $redis->get('PLAY:'.$playerData);
                if($playData == $from->resourceId) {
                    $redis->set('PLAY:'.$playerData, '');

                    $playSelf = "button_play_self**false";
                    $playOther = "button_play**true**Please push";

                    // PUBLIC
                    // SELF
                    $this->clients[$from->resourceId]->send($playSelf);
                    // BROADCAST
                    foreach ($getRoomKeyData as $key => $resourceId) {
                        if(isset($this->clients[$resourceId])) {
                            $playData = $redis->get('PLAY:'.$playerData);

                            if($resourceId != $playData) {
                                $this->clients[$resourceId]->send($playOther);
                            }
                        }
                    }
                } else {
                    if($playData == '') {
                        // // INIT PLAY THE GAME BIND ROOM
                        $redis->set('PLAY:'.$playerData, $from->resourceId);

                        $playSelf = "button_play_self**true";
                        $playOther = "button_play**false**".$expl_player[1]." is Playing2";

                        // PUBLIC
                        // SELF
                        $this->clients[$from->resourceId]->send($playSelf);
                        // BROADCAST
                        foreach ($getRoomKeyData as $key => $resourceId) {
                            if(isset($this->clients[$resourceId])) {
                                $playData = $redis->get('PLAY:'.$playerData);

                                if($resourceId != $playData) {
                                    $this->clients[$resourceId]->send($playOther);
                                }
                            }
                        }
                    } else {
                        // GET DATA PLAY ROOM DATA
                        $getPlayRoomData = $redis->hget('ROOMS:'.$playerData, $playData);
                        // EXPLODE PLAY DATA
                        $expl_play = explode(':', $getPlayRoomData);

                        $playSelf = "button_play_self**true";
                        $playOther = "button_play**false**".$expl_player[1]." is Playing3";

                        // PUBLIC
                        // SELF
                        $this->clients[$from->resourceId]->send($playSelf);
                        // BROADCAST
                        foreach ($getRoomKeyData as $key => $resourceId) {
                            if(isset($this->clients[$resourceId])) {
                                $playData = $redis->get('PLAY:'.$playerData);

                                if($resourceId != $playData) {
                                    $this->clients[$resourceId]->send($playOther);
                                }
                            }
                        }
                    }
                }
            } else {
                $playOther = "pesan**Perintah bermain salah \n";
            }           
        } elseif($expl_mgg[0] == 'control') {
            $command = $expl_mgg[1];
            echo "[".date("Y-m-d H:i:s")."] = WS server  : ".$command."\n";

            // 'ws://204.48.28.161:3000'
            $WsClient = new WSClient("ws://204.48.28.161:3000");
            $WsClient->send($command);

            echo "[".date("Y-m-d H:i:s")."] = Game server ".$playerData."] : ".$WsClient->receive()."\n";
        } else {
            $playSelf = $msg;
            // PUBLIC
            // SELF
            $this->clients[$from->resourceId]->send($playSelf);
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

        // GET DATA FROM PLAY
        $playOther = '';
        $playData = $redis->get('PLAY:'.$playerData);
        if($playData == $conn->resourceId) {
            $redis->set('PLAY:'.$playerData, '');
            $playOther = "button_play**true**Please push";
        }

        // LOG
        echo "[".$conn->resourceId."] : Player ID ".$expl_player[0]." is ".$expl_player[1]." disconnect\n";
        
        // DELETE DATA FROM PLAYERS
        $redis->del('PLAYERS:'.$conn->resourceId);

        // DELETE DATA FROM ROOMS
        $redis->hdel('ROOMS:'.$playerData, $conn->resourceId);

        // BROADCAST
        foreach ($getRoomKeyData as $key => $resourceId) {
            if(isset($this->clients[$resourceId])) {
                $this->clients[$resourceId]->send("pesan**Player ".$expl_player[1]." terputus");
                
                if($playOther != '') {
                    $this->clients[$resourceId]->send($playOther);
                }
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
    8085
);

echo "Socket On 8085 \n";
$server->run();

