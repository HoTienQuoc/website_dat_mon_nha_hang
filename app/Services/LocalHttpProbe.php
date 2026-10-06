<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local-only HTTP client for the isolated acceptance command. */
class LocalHttpProbe
{
    public function session(?User $user = null, array $attributes = []): array
    {
        $id = Str::random(40);
        $token = Str::random(40);
        $payload = ['locale' => 'vi', ...$attributes, '_token' => $token];
        if ($user) {
            $payload[auth()->guard()->getName()] = $user->id;
        }
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user?->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Local acceptance', 'payload' => base64_encode(config('session.serialization') === 'json' ? json_encode($payload) : serialize($payload)), 'last_activity' => time()]);
        $name = config('session.cookie');
        $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false);

        return ['id' => $id, 'token' => $token, 'cookie' => $name.'='.rawurlencode($value)];
    }

    public function attributes(array $session): array
    {
        $payload = base64_decode(DB::table('sessions')->where('id', $session['id'])->value('payload'));

        return config('session.serialization') === 'json' ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : unserialize($payload, ['allowed_classes' => false]);
    }

    /** @return array<int, array{status:int, location:string, body:string, ms:float, error:int}> */
    public function batch(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $results = [];
        foreach ($requests as $index => $request) {
            if (! str_starts_with($request['path'], '/') || str_starts_with($request['path'], '//')) {
                throw new \InvalidArgumentException('A local path is required.');
            }
            $handle = curl_init('http://127.0.0.1:9002'.$request['path']);
            $data = $request['data'] ?? [];
            if (($request['method'] ?? 'POST') !== 'GET' && ! ($request['omit_csrf'] ?? false)) {
                $data['_token'] = $request['session']['token'];
            }
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CUSTOMREQUEST => $request['method'] ?? 'POST', CURLOPT_COOKIE => $request['session']['cookie'], CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_POSTFIELDS => http_build_query($data)]);
            $handles[spl_object_id($handle)] = ['index' => $index, 'start' => microtime(true)];
            curl_multi_add_handle($multi, $handle);
        }
        do {
            curl_multi_exec($multi, $running);
            while ($info = curl_multi_info_read($multi)) {
                $handle = $info['handle'];
                $meta = $handles[spl_object_id($handle)];
                $raw = curl_multi_getcontent($handle);
                $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
                preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $location);
                $results[$meta['index']] = ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'location' => trim($location[1] ?? ''), 'body' => substr($raw, $headerSize), 'ms' => round((microtime(true) - $meta['start']) * 1000, 2), 'error' => $info['result']];
                unset($handles[spl_object_id($handle)]);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            if ($handles) {
                curl_multi_select($multi, 0.2);
            }
        } while ($handles);
        curl_multi_close($multi);
        ksort($results);

        return $results;
    }
}
