<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMq extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:mq';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    private function getCon()
    {
        static $con = false;
        if ($con === false || $con->isConnected() === false) {

            $rabbitmqConfig = [
                "RABBITMQ_HOST"               => env('RABBITMQ_HOST'),
                "RABBITMQ_PORT"               => env('RABBITMQ_PORT'),
                "RABBITMQ_USER"               => env('RABBITMQ_USER'),
                "RABBITMQ_PASSWORD"           => env('RABBITMQ_PASSWORD'),
                'RABBITMQ_VHOST'              => '/',
                'RABBITMQ_INSIST'             => false,
                'RABBITMQ_LOGIN_METHOD'       => 'AMQPLAIN',
                'RABBITMQ_LOGIN_RESPONSE'     => null,
                'RABBITMQ_LOCALE'             => 'en_US',
                'RABBITMQ_CONNECTION_TIMEOUT' => 10.0
            ];
            //检查rabbitmq连接配置项
            $check_config_msg = "";
            $check_config_msg .= empty($rabbitmqConfig["RABBITMQ_HOST"]) ? ".env文件： RABBITMQ_HOST 未配置" . PHP_EOL : "";
            $check_config_msg .= empty($rabbitmqConfig["RABBITMQ_PORT"]) ? ".env文件： RABBITMQ_PORT 未配置" . PHP_EOL : "";
            $check_config_msg .= empty($rabbitmqConfig["RABBITMQ_USER"]) ? ".env文件： RABBITMQ_USER 未配置" . PHP_EOL : "";
            $check_config_msg .= empty($rabbitmqConfig["RABBITMQ_PASSWORD"]) ? ".env文件： RABBITMQ_PASSWORD 未配置" . PHP_EOL : "";

            if (!empty($check_config_msg)) {
                throw new \Exception(PHP_EOL . $check_config_msg);
            }

            $con = new AMQPStreamConnection(
                $rabbitmqConfig['RABBITMQ_HOST'],
                $rabbitmqConfig['RABBITMQ_PORT'],
                $rabbitmqConfig['RABBITMQ_USER'],
                $rabbitmqConfig['RABBITMQ_PASSWORD'],
                $rabbitmqConfig['RABBITMQ_VHOST'],
                $rabbitmqConfig['RABBITMQ_INSIST'],
                $rabbitmqConfig['RABBITMQ_LOGIN_METHOD'],
                $rabbitmqConfig['RABBITMQ_LOGIN_RESPONSE'],
                $rabbitmqConfig['RABBITMQ_LOCALE'],
                $rabbitmqConfig['RABBITMQ_CONNECTION_TIMEOUT']
            );
        }

        return $con;
    }

    private function request_by_curl($remote_server, $post_string)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_server);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=utf-8'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // 线下环境不用开启curl证书验证, 未调通情况可尝试添加该代码
        // curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);
        // curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

    public function sendQueue($exchange, $msg)
    {
        $ret = false;
        $con = $this->getCon();
        if ($con) {
            $channel = $con->channel();
            $channel->confirm_select();
            $isSendOk = false;
                $channel->set_ack_handler(function($message)use(&$isSendOk){
                    $isSendOk = true;
                });
                $channel->set_nack_handler(function ($message)use ($exchange,$msg){
                    dispatch((new RabbitMqJob($exchange,$msg))->delay(60));
                });
            $channel->exchange_declare($exchange, 'fanout', false, true, false);
            $amqMsg = new AMQPMessage($msg, ['delivery' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            $ret    = $channel->basic_publish($amqMsg, $exchange);
            $channel->wait_for_pending_acks(3);
            $channel->close();
            if (!$isSendOk) {
                throw new \Exception("发送消息失败");
            }
        } else {
            throw new \Exception("链接RabbitMq失败");
        }

        return $ret;
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        for ($i = 1; $i <= 10000; $i++) {
            $this->sendQueue("zhaoli_exchange",json_encode(['test' => date("YmdHis", time())."===".$i]));
            var_dump($i);
        }
    }
}
