<?php

use App\Broadcasting\OutletRealtimeChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('outlet.{outletId}.orders', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.kitchen', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.payments', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.qr-orders', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.reservations', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.pos-sessions', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.inventory-alerts', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.printer-queue', OutletRealtimeChannel::class);
Broadcast::channel('outlet.{outletId}.hardware', OutletRealtimeChannel::class);
