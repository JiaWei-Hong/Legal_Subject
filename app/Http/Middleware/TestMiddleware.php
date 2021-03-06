<?php

namespace App\Http\Middleware;

use Closure;
use App\User;

class TestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $data = User::where('api_token',$request['token'])->first();

        if($data['permission'] == '管理員'){
            return $next($request);
        }

        return response('Permission Denied',403);
    }
}
