<?php

namespace App\Http\Middleware;

use App\Enums\User\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->status === UserStatus::INACTIVE) {
            return response()->json([
                'message' => 'You do not have permission to perform any action. Please contact the administration for assistance.',
            ], 403);
        }
//
//        if ($user->status === UserStatus::BLOCKED) {
//            return response()->json([
//                'message' => 'You have been blocked from the system.',
//            ], 403);
//        }

        return $next($request);
    }
}
