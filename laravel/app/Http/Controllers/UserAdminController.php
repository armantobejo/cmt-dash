<?php

namespace App\Http\Controllers;

use App\Support\CmtAuth;
use App\Support\CmtUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * User management — contract parity with the Python app (main.py):
 *   GET  /users                 admin+    list + create form
 *   POST /users/new             admin+    create; errors re-render list (200)
 *   GET  /users/{id}/edit       admin+    edit form; unknown id 303 -> /users
 *   POST /users/{id}/edit       admin+    update; errors re-render edit (200)
 *   POST /users/{id}/delete     superadmin; self-deletion silently no-ops
 */
class UserAdminController extends Controller
{
    public function __construct(
        protected CmtAuth $auth,
    ) {
    }

    public function index(Request $request)
    {
        return view('users', [
            'cmtUser' => $request->get('cmt_user'),
            'users' => $this->auth->listUsers(),
            'editing' => null,
            'error' => null,
        ]);
    }

    public function store(Request $request)
    {
        /** @var CmtUser $actor */
        $actor = $request->get('cmt_user') ?? app(CmtUser::class);
        $error = $this->auth->createUser(
            (string) $request->input('username', ''),
            (string) $request->input('display_name', ''),
            (string) $request->input('role', 'staff'),
            (string) $request->input('password', ''),
            (string) $request->input('customer_company', ''),
            $actor->level(),
        );

        if ($error !== null) {
            return view('users', [
                'cmtUser' => $request->get('cmt_user'),
                'users' => $this->auth->listUsers(),
                'editing' => null,
                'error' => $error,
            ]);
        }

        return redirect()->to('/users', 303);
    }

    public function edit(Request $request, int $userId)
    {
        $editing = $this->auth->getUser($userId);
        if ($editing === null) {
            return redirect()->to('/users', 303);
        }

        return view('users', [
            'cmtUser' => $request->get('cmt_user'),
            'users' => $this->auth->listUsers(),
            'editing' => $editing,
            'error' => null,
        ]);
    }

    public function update(Request $request, int $userId)
    {
        /** @var CmtUser $actor */
        $actor = $request->get('cmt_user') ?? app(CmtUser::class);
        $error = $this->auth->updateUser(
            $userId,
            (string) $request->input('display_name', ''),
            (string) $request->input('role', 'staff'),
            (string) $request->input('customer_company', ''),
            (string) $request->input('password', ''),
            $actor->level(),
        );

        if ($error !== null) {
            return view('users', [
                'cmtUser' => $request->get('cmt_user'),
                'users' => $this->auth->listUsers(),
                'editing' => $this->auth->getUser($userId),
                'error' => $error,
            ]);
        }

        return redirect()->to('/users', 303);
    }

    public function destroy(Request $request, int $userId)
    {
        /** @var CmtUser $actor */
        $actor = $request->get('cmt_user');

        // Prevent self-deletion (Python parity: silent 303, no error).
        $target = $this->auth->getUser($userId);
        if ($target !== null && $target['username'] === $actor->username) {
            return redirect()->to('/users', 303);
        }

        $this->auth->deleteUser($userId);

        return redirect()->to('/users', 303);
    }
}
