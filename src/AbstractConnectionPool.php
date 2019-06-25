<?php

namespace Mix\Pool;

use Mix\Component\AbstractComponent;
use Mix\Component\ComponentInterface;
use Mix\Concurrent\Coroutine\Channel;

/**
 * Class AbstractConnectionPool
 * @package Mix\Pool
 * @author liu,jian <coder.keda@gmail.com>
 */
abstract class AbstractConnectionPool extends AbstractComponent
{

    /**
     * 协程模式
     * @var int
     */
    const COROUTINE_MODE = ComponentInterface::COROUTINE_MODE_REFERENCE;

    /**
     * 最多可空闲连接数
     * @var int
     */
    public $maxIdle = 5;

    /**
     * 最大连接数
     * @var int
     */
    public $maxActive = 5;

    /**
     * 拨号器
     * @var \Mix\Pool\DialerInterface
     */
    public $dialer;

    /**
     * 连接队列
     * @var \Mix\Concurrent\Coroutine\Channel
     */
    protected $_queue;

    /**
     * 活跃连接集合
     * @var array
     */
    protected $_actives = [];

    /**
     * 初始化事件
     */
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 创建协程队列
        $this->_queue = new Channel($this->maxIdle);
    }

    /**
     * 创建连接
     * @return mixed
     */
    protected function createConnection()
    {
        $connection                 = $this->dialer->dial();
        $connection->connectionPool = $this;
        return $connection;
    }

    /**
     * 获取连接
     * @return mixed
     */
    public function getConnection()
    {
        if ($this->getIdleNumber() > 0 || $this->getTotalNumber() >= $this->maxActive) {
            // 队列有连接，从队列取
            // 达到最大连接数，从队列取
            $connection = $this->pop();
        } else {
            // 创建连接
            $connection = $this->createConnection();
        }
        // 登记
        $id                  = spl_object_hash($connection);
        $this->_actives[$id] = ''; // 不可保存外部连接的引用，否则导致外部连接不析构
        // 返回
        return $connection;
    }

    /**
     * 释放连接
     * @param $connection
     * @return bool
     */
    public function release($connection)
    {
        $id = spl_object_hash($connection);
        // 判断是否已释放
        if (!isset($this->_actives[$id])) {
            return false;
        }
        // 移除登记
        unset($this->_actives[$id]); // 注意：必须是先减 actives，否则会 maxActive - maxIdle <= 1 时会阻塞
        // 入列
        return $this->push($connection);
    }

    /**
     * 丢弃连接
     * @param $connection
     * @return bool
     */
    public function discard($connection)
    {
        $id = spl_object_hash($connection);
        // 判断是否已丢弃
        if (!isset($this->_actives[$id])) {
            return false;
        }
        // 移除登记
        unset($this->_actives[$id]);
        // 返回
        return true;
    }

    /**
     * 获取连接池的统计信息
     * @return array
     */
    public function getStats()
    {
        return [
            'total'  => $this->getTotalNumber(),
            'idle'   => $this->getIdleNumber(),
            'active' => $this->getActiveNumber(),
        ];
    }

    /**
     * 放入连接
     * @param $connection
     * @return bool
     */
    protected function push($connection)
    {
        if ($this->getIdleNumber() < $this->maxIdle) {
            return $this->_queue->push($connection);
        }
        return false;
    }

    /**
     * 弹出连接
     * @return mixed
     */
    protected function pop()
    {
        return $this->_queue->pop();
    }

    /**
     * 获取队列中的连接数
     * @return int
     */
    protected function getIdleNumber()
    {
        $count = $this->_queue->stats()['queue_num'];
        return $count < 0 ? 0 : $count;
    }

    /**
     * 获取活跃的连接数
     * @return int
     */
    protected function getActiveNumber()
    {
        return count($this->_actives);
    }

    /**
     * 获取当前总连接数
     * @return int
     */
    protected function getTotalNumber()
    {
        return $this->getIdleNumber() + $this->getActiveNumber();
    }

}
