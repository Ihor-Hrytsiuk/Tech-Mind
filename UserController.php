<?php

namespace App\Http\Controllers;

use App\CoursesOrder;
use App\Role;
use App\User;
use App\UsersToLessons;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index()
    {
        if (Auth::user()->hasRole('teacher')) {
            $data['users'] = User::whereHas('roles', function ($query) {
                return $query->where('slug', 'client');
            })->where('teacher_id', Auth::id())->paginate(25);
        } else {
            $data['users'] = User::paginate(25);
        }

        return view('pages/users/list', $data);
    }

    public function autocomplete(Request $request)
    {
        if ($request->has('search')) {
            $result = User::where('name', 'like', '%'.$request->search.'%')->get();

            return response()->json($result);
        }

        return response()->json([]);
    }

    public function teacherAutocomplete(Request $request)
    {
        $result = [];
        if ($request->has('search')) {
            $users = User::where('name', 'like', '%'.$request->search.'%')->get();
            foreach ($users as $user) {
                if ($user->hasRole('teacher')) {
                    $result[]=[
                        'id'    => $user->id,
                        'name'  => $user->name,
                    ];
                }
            }

            return response()->json($result);
        }

        return response()->json([]);
    }

    public function getUserInfo($id)
    {
        $data['user_info']  = User::where('id', $id)->with('courses')->first();

        return view('pages/users/info', $data);
    }

    public function getCourseInfo($user_id, $course_id)
    {
        $data['lessons'] = UsersToLessons::where('user_id', $user_id)->where('course_id', $course_id)->with('lessonInfo')->get();

        return view('pages/users/lessons_info', $data);
    }

    public function showEditForm($id)
    {
        $data['user_info']  = User::find($id);
        $data['roles']      = Role::all();

        return view('pages/users/form', $data);
    }

    public function create()
    {
    }

    public function editUser($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'name.required'     => 'Имя - обязательный параметр',
            'name.string'       => 'Имя должено строкой',
            'email.required'    => 'email - обязательный параметр',
            'email.string'      => 'email должен быть строкой',
            'email.email'       => 'Неверный формат e-mail',

        ]);

        if (! $validator->fails()) {
            $to_update = [
                'name'  => $request->name,
                'email' => $request->email,
            ];

            $result = User::where('email', $request->email)->where('id', '!=', $id)->first();

            if (! empty($result)) {
                return response()->redirectTo('admin/users')->withErrors(['password' => ['Этот email уже занят.']]);
            }

            if ($request->has('password') and ! empty($request->password)) {
                if ($request->has('password') && $request->has('password_confirmation') && $request->password == $request->password_confirmation) {
                    $to_update['password'] = Hash::make($request->password);
                } else {
                    return response()->json(['errors' => ['password' => ['Пароли не совпадают.']]], 200);
                }
            }

            if (! empty($request->role)) {
                $role = Role::where('slug', $request->role)->first();
                $user = User::find($id);

                $user->deletePermissions('client', 'admin', 'teacher', 'manager');
                $user->roles()->attach($role);
            }

            User::where('id', $id)->update($to_update);

            return response()->redirectTo('admin/users');
        } else {
            return response()->redirectTo('admin/user/'.$id)->withErrors($validator);
        }
    }

    public function destroy($id)
    {
        UsersToLessons::where('user_id', $id)->delete();
        CoursesOrder::where('user_id', $id)->delete();
        User::destroy($id);

        return response()->redirectTo('admin/users');
    }
}
