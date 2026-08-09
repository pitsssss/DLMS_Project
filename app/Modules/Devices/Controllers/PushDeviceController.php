<?php

namespace App\Modules\Devices\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Devices\Requests\RegisterPushTokenRequest;
use App\Modules\Devices\Requests\UnregisterPushTokenRequest;
use App\Modules\Devices\Services\PushDeviceService;

class PushDeviceController extends Controller
{
    public function __construct(
        private readonly PushDeviceService $pushDevices,
    ) {}

    public function register(RegisterPushTokenRequest $request)
    {
        $validated = $request->validated();

        $device = $this->pushDevices->register($request->user(), [
            'device_id' => $validated['device_id'],
            'platform' => $validated['platform'],
            'token' => $validated['token'],
        ]);

        return $this->successResponse([
            'device_id' => $device->device_id,
            'platform' => $device->platform,
            'registered' => true,
        ], 'messages.devices.registered');
    }

    public function unregister(UnregisterPushTokenRequest $request)
    {
        $validated = $request->validated();

        $this->pushDevices->unregister($request->user(), $validated['device_id']);

        return $this->successResponse([
            'device_id' => $validated['device_id'],
            'unregistered' => true,
        ], 'messages.devices.unregistered');
    }
}
