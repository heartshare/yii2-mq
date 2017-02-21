<?php
/**
 * @link http://www.tintsoft.com/
 * @copyright Copyright (c) 2012 TintSoft Technology Co. Ltd.
 * @license http://www.tintsoft.com/license/
 */
namespace xutl\mq\db;

use Yii;
use yii\helpers\Json;
use yii\base\Object;
use yii\db\Connection;
use xutl\mq\QueueInterface;

/**
 * Class Queue
 * @package xutl\mq\db
 */
class Queue extends Object implements QueueInterface
{
    /**
     * @var Connection;
     */
    public $db;

    /**
     * @var string
     */
    public $queueName;

    /**
     * @var integer
     */
    public $expire = 60;

    /**
     * @param array $message
     * @param int $delay
     * @return false|string
     */
    public function sendMessage($message, $delay = 0)
    {
        $this->db->createCommand()->insert('{{%queue}}', [
            'queue' => $this->queueName,
            'reserved' => false,
            'reserved_at' => null,
            'payload' => Json::encode($message),
            'available_at' => time() + $delay,
            'created_at' => time(),
        ])->execute();
        return $this->db->lastInsertID;
    }

    /**
     * 获取消息
     * @return array|bool
     */
    public function receiveMessage()
    {
        //遍历保留和等待
        foreach ([':delayed', ':reserved'] as $type) {
            $options = ['cas' => true, 'watch' => $this->queueName . $type];
            $this->client->transaction($options, function (MultiExec $transaction) use ($type) {
                $data = $this->client->zrangebyscore($this->queueName . $type, '-inf', $time = time());
                if (!empty($data)) {
                    $transaction->zremrangebyscore($this->queueName . $type, '-inf', $time);
                    //压入队列
                    foreach ($data as $payload) {
                        $transaction->rpush($this->queueName, [$payload]);
                    }
                }
            });
        }

        $data = $this->client->lpop($this->queueName);

        if ($data === null) {
            return false;
        }

        $this->client->zadd($this->queueName . ':reserved', [$data => time() + $this->expire]);

        $receiptHandle = $data;
        $data = Json::decode($data);

        return [
            'messageId' => $data['id'],
            'MessageBody' => $data['body'],
            'receiptHandle' => $receiptHandle,
            'queue' => $this->queueName,
        ];
    }

    /**
     * 修改消息可见时间
     * @param string $receiptHandle
     * @param int $visibilityTimeout
     * @return bool
     */
    public function changeMessageVisibility($receiptHandle, $visibilityTimeout)
    {
        $this->deleteMessage($receiptHandle);
        if ($visibilityTimeout > 0) {
            $this->client->zadd($this->queueName . ':delayed', [$receiptHandle => time() + $visibilityTimeout]);
        } else {
            $this->client->rpush($this->queueName, [$receiptHandle]);
        }
    }

    /**
     * 删除消息
     * @param string $receiptHandle
     * @return bool
     */
    public function deleteMessage($receiptHandle)
    {
        $this->client->zrem($this->queueName . ':reserved', $receiptHandle);
    }
}