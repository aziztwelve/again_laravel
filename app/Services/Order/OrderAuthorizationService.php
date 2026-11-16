<?php

namespace App\Services\Order;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;

class OrderAuthorizationService
{
    /**
     * Проверить может ли пользователь просматривать заказ
     *
     * @param User|Client $user
     * @param Order $order
     * @return bool
     */
    public function canView($user, Order $order): bool
    {
        // Если это клиент - может видеть только свои заказы
        if ($user instanceof Client) {
            return $order->client_id === $user->id;
        }

        // Админы могут видеть все заказы
        return true;
    }

    /**
     * Проверить может ли пользователь редактировать заказ
     *
     * @param User|Client $user
     * @return bool
     */
    public function canUpdate($user): bool
    {
        // Только админы могут редактировать
        return !($user instanceof Client);
    }

    /**
     * Проверить может ли пользователь удалять заказ
     *
     * @param User|Client $user
     * @return bool
     */
    public function canDelete($user): bool
    {
        // Клиенты не удаляют заказы никогда.
        if (! $user instanceof User) {
            return false;
        }

        // Удаление — отдельное право orders.delete: есть у супер-администратора
        // (через роль) и у тех, кому его выдали вручную. Менеджерам по умолчанию недоступно.
        return $user->hasRole('super-admin') || $user->hasPermission('orders.delete');
    }

    /**
     * Проверить может ли пользователь отменить заказ
     *
     * @param User|Client $user
     * @param Order $order
     * @return bool
     */
    public function canCancel($user, Order $order): bool
    {
        // Клиент может отменить свой заказ
        if ($user instanceof Client) {
            return $order->client_id === $user->id;
        }

        // Админ может отменить любой заказ
        return true;
    }
}
