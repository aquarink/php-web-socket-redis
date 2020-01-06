<?php

namespace MyApp;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Socket implements MessageComponentInterface {

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {

        // Store the new connection in $this->clients
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";
        echo count($this->clients)." User Connected! \n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {

        foreach ( $this->clients as $client ) {

            if ( $from->resourceId == $client->resourceId ) {
                continue;
            }

            $client->send( "Client $from->resourceId said $msg" );
            
        }

        $queue = array();
        if($msg == 'play') {
            $queue[$from->resourceId] = 'play';
        }

        // echo "Client ".$from->resourceId." said $msg \n";
        echo "Client ".$from->resourceId." queue ".count($queue)."\n";
    }

    public function onClose(ConnectionInterface $conn) {
        echo "Disconnected! ({$conn->resourceId})\n";
        echo (count($this->clients) - 1)." User Connected! \n";
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error Connection! ({$conn->resourceId})\n";
        $conn->close();
    }
}