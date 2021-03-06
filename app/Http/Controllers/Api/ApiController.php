<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Exports\UsersExport;
use App\Exports\FullMarksExport;
use App\User;
use App\Category;
use App\Question;
use App\Option;
use App\Answer;
use App\SchoolYear;
use App\SchoolYearQuestions;
use App\Scores;
use App\Record;
use App\Classname;
use Excel;
use PhpOffice\PhpSpreadsheet\Shared\JAMA\CholeskyDecomposition;
use Storage;

set_time_limit(0);

class ApiController extends Controller
{
    public function login(Request $request)
    {
        $input = $request->all();
        $query = User::where('account', $input['account'])->first();

        if (!is_null($query) && Hash::check($input['password'], $query->password)) {
            return [
                'status' => true,
                'msg' => '登入成功',
                'data' => [
                    'username' => $query->username,
                    'permission' => $query->permission,
                    'id' => $query->id,
                    'token' => $query->api_token
                ]
            ];
        }

        return [
            'status' => false,
            'msg' => '帳號或密碼輸入錯誤!',
            'data' => null
        ];
    }

    public function getSchoolYear()
    {
        return SchoolYear::all();
    }

    public function addSchoolYear(Request $request)
    {
        $query = SchoolYear::where('year', $request->schoolYear)->get();

        if ($query->count() > 0) {
            return [
                'status' => false,
                'msg' => '學年度已存在。'
            ];
        }

        $data = SchoolYear::create([
            'year' => $request->schoolYear
        ]);

        return [
            'status' => true,
            'id' => $data->id,
            'msg' => '創建成功'
        ];
    }

    public function toggleSchoolYear(Request $request)
    {
        foreach (SchoolYear::where('id', '!=', $request->id)->get() as $data) {
            $data->update(['isOpen' => 0]);
        }

        $query = SchoolYear::where('id', $request->id)->first();
        $query->update(['isOpen' => !$request->bool]);

        return response([
            'msg' => !$request->bool ? '學年度已開啟。' : '學年度已關閉。',
        ]);
    }

    public function getSchoolYearQuestions(Request $request)
    {
        $raw_data = SchoolYearQuestions::where('year_id', $request->id)->inRandomOrder()->get();

        $data = [
            'questions' => [],
            'options' => [],
        ];


        foreach ($raw_data as $searchData) {
            array_push($data['questions'], Question::where('id', $searchData->question_id)->select('id', 'question')->first());
            array_push($data['options'], $this->getOption($searchData->question_id));
        }

        return $data;
    }

    public function getQuestions(Request $request)
    {
        $raw_data = Question::where('year_id', $request->id)->inRandomOrder()->get();

        $data = [
            'questions' => [],
            'options' => [],
            'answers' => []
        ];

        foreach ($raw_data as $searchData) {
            array_push($data['questions'], Question::where('id', $searchData->id)->select('id', 'question')->first());
            array_push($data['options'], $this->getOption($searchData->id));
            array_push($data['answers'], $this->getAnswer($searchData->id));
        }

        return $data;
    }

    public function getCategories()
    {
        return Category::select('id', 'name')->get();
    }

    public function getQuestion($id)
    {
        return Question::find($id)->first();
    }

    public function getOption($question_id)
    {
        return Option::where('question_id', $question_id)->select('id', 'value')->get();
    }

    public function getAnswer($question_id)
    {
        return Answer::where('question_id', $question_id)->select('option_id')->first();
    }

    public function addScore(Request $request)
    {
        $query = Scores::where([
            ['category_id', '=', $request->category_id],
            ['user_id', '=', $request->user_id],
            ['year_id', '=', $request->year_id]
        ])->count();

        if ($query < 2) {
            $scoreData = collect($request->questions)->map(function ($d, $i) use ($request) {
                return $this->getAnswer($d['id'])->option_id == $request->userAnswer[$i];
            });

            $score = Scores::create([
                'category_id' => $request->category_id,
                'user_id' => $request->user_id,
                'year_id' => $request->year_id,
                'score' => count($scoreData->filter(fn($d) => $d)) * 2
            ]);

            foreach ($request->questions as $key => $data) {
                Record::create([
                    's_id' => $score->id,
                    'question_id' => $data['id'],
                    'correct' => $scoreData[$key]
                ]);
            }

            return response()->json([
                'status' => true,
                'msg' => '成績保存成功。'
            ]);
        }

        return response()->json([
            'status' => false,
            'msg' => '測驗次數已經達到兩次。'
        ]);
    }

    public function getScoresCount(Request $request)
    {
        return Scores::where([
            ['user_id', '=', $request->id],
            ['year_id', '=', $request->yearId]
        ])->get()->count();
    }

    public function getScores(Request $request)
    {
        $data = collect();

        foreach (Scores::where('user_id', $request->id)->get() as $d) {
            $year_data = SchoolYear::find($d->year_id);
            $data->push([$d->id, Category::find($d->category_id)->name, $year_data->year, $d->score, $d->created_at, $year_data->isAnswerRecord]);
        }

        return $data;
    }

    public function checkPermission(Request $request)
    {
        if (strlen($request->token) == 0)
            return 'null';

        $data = collect(User::where('api_token', $request->token)->first());

        return $data->has('permission') ? $data['permission'] : 'null';
    }

    public function logout(Request $request)
    {
        return User::where('api_token', $request->token)->update(['api_token' => Str::random(80)]);
    }

    public function getFinishData(Request $request)
    {
        $input = $request->all();
        $return_data = [];
        $raw_data = User::all()->map(function ($d) use ($input) {
            $userData = Scores::where([
                'user_id' => $d->id,
                'year_id' => $input['id']
            ])->get();
            $userMaxScore = $userData->max('score');

            return [
                'id' => collect($userData->filter(function ($d) use ($userMaxScore) {
                    return $d->score === $userMaxScore;
                })->first())->get('id'),
                'score' => $userMaxScore
            ];
        })->filter(function ($d) {
            return $d['score'] != null;
        })->map(function ($v) {
            return collect(Record::where('s_id', $v)->get());
        });

        foreach ($raw_data as $data) {
            foreach ($data as $item) {
                if (!array_key_exists($item->question_id, $return_data)) {
                    $return_data[$item->question_id] = [
                        'question' => Question::find($item->question_id)->question,
                        'correct' => 0,
                        'incorrect' => 0
                    ];
                }

                $return_data[$item->question_id][$item->correct ? 'correct' : 'incorrect']++;
            }
        }

        SchoolYear::where('id', $input['id'])->update([
            'isOpen' => 0
        ]);

        return [
            'msg' => '已計算完成。',
            'data' => $return_data
        ];
    }

    public function importUsers(Request $request)
    {
        $filename = time() . '.xlsx';
        Storage::disk('public')->put($filename, file_get_contents($request->file));
        $userLists = Excel::toArray([], storage_path() . '/app/public/' . $filename)[0];
        array_shift($userLists);

        foreach ($userLists as $data) {
            $data = array_filter($data);
            $user = User::where([
                ['account', '=', $data[3]],
                ['username', '!=', $data[4]]
            ])->first();
            $class = Classname::where('name', $data[1])->first();

            if (is_null($class)) {
                $class = Classname::create(['name' => $data[1]]);
            }

            $insertData = [
                'account' => $data[3],
                'password' => Hash::make($data[5]),
                'username' => $data[4],
                'classnumber' => $data[2]
                , 'class_id' => $class->id,
                'api_token' => Str::random(80)
            ];

            if ($user) {
                $user->update($insertData);
            } else {
                $userExists = User::where([
                    ['account', '=', $data[3]],
                    ['username', '=', $data[4]]
                ])->first();

                if (is_null($userExists)) {
                    User::create($insertData);
                }
            }
        }

        Storage::disk('public')->delete($filename);

        return ['msg' => '學生資料已導入完成',];
    }

    public function export($id)
    {
        $return_data = [];
        $raw_data = User::all()->map(function ($d) use ($id) {
            $userData = Scores::where([
                'user_id' => $d->id,
                'year_id' => $id
            ])->get();
            $userMaxScore = $userData->max('score');

            return [
                'id' => collect($userData->filter(function ($d) use ($userMaxScore) {
                    return $d->score === $userMaxScore;
                })->first())->get('id'),
                'score' => $userMaxScore
            ];
        })->filter(function ($d) {
            return $d['score'] != null;
        })->map(function ($v) {
            return collect(Record::where('s_id', $v)->get());
        });

        foreach ($raw_data as $data) {
            foreach ($data as $item) {
                if (!array_key_exists($item->question_id, $return_data)) {
                    $return_data[$item->question_id] = [
                        'question' => Question::find($item->question_id)->question,
                        'correct' => 0,
                        'incorrect' => 0
                    ];
                }

                $return_data[$item->question_id][$item->correct ? 'correct' : 'incorrect']++;
            }
        }

        $return_data = array_map(function ($d) {
            return [
                'question' => $d['question'],
                'correct' => $d['correct'] / ($d['correct'] + $d['incorrect']) * 100 . '%',
                'incorrect' => $d['incorrect'] / ($d['correct'] + $d['incorrect']) * 100 . '%',
            ];
        }, $return_data);

        return Excel::download(new UsersExport($return_data), 'report.xlsx');
    }

    public function fullMarksExport(Request $request)
    {
        $data = $this->getFullMarks(new Request($request->all()));

        return Excel::download(new FullMarksExport($data), 'test.xlsx');
    }

    public function importQuestion(Request $request)
    {
        $filename = time() . '.xlsx';

        Storage::disk('public')->put($filename, file_get_contents($request->file));

        $userData = Excel::toArray([], storage_path() . '/app/public/' . $filename)[0];
        $data = collect([]);

        array_shift($userData);

        foreach ($userData as $d) {
            $questionStr = str_replace(
                ['（', '）', 'a', 'b', 'c', 'd', ' ', 'Ａ', 'Ｂ', 'Ｃ', 'Ｄ'],
                ['(', ')', 'A', 'B', 'C', 'D', '', 'A', 'B', 'C', 'D'],
                $d[2]
            );
            preg_match('/(\(A\).*)(\(B\).*)(\(C\).*)(\(D\).*)|(\(A\).*)(\(B\).*)(\(C\).*)|(\(A\).*)(\(B\).*)/m', $questionStr, $match);

            array_shift($match);
            $questionData = Question::create([
                'year_id' => $request->yearId,
                'question' => explode('(A)', $questionStr)[0]
            ]);

            $match = array_filter($match);
            $data->push([$d[1], $match]);

            for ($i = 0; $i < count($match); $i++) {
                $optionData = Option::create([
                    'question_id' => $questionData->id,
                    'value' => array_values($match)[$i]
                ]);

                if (chr(65 + $i) == str_replace(' ', '', $d[1])) {
                    Answer::create([
                        'question_id' => $questionData->id,
                        'option_id' => $optionData->id
                    ]);
                }
            }
        }


        Storage::disk('public')->delete($filename);

        $questions = Question::where('year_id', $request->yearId)->inRandomOrder()->take(50)->get();

        foreach ($questions as $question) {
            SchoolYearQuestions::create([
                'category_id' => 1,
                'year_id' => $request->yearId,
                'question_id' => $question->id
            ]);
        }

        return [
            'msg' => '題目已經導入完成。',
        ];
    }

    public function getFullMarks(Request $request)
    {
        return collect(Scores::where([
            ['year_id', '=', $request->year_id],
            ['score', '=', 100]
        ])->get())->map(function ($d) {
            $user = User::where('id', $d->user_id)->first();
            $class = Classname::where('id', $user->class_id)->first();

            return [
                'className' => $class['name'],
                'studentNumber' => $user['account'],
                'studentName' => $user['username'],
            ];
        })->unique('studentNumber');
    }

    public function getScoresDetail(Request $request)
    {
        $checkIsUserScore = Scores::where([
            ['id', '=', $request->id],
            ['user_id', '=', $request->userId]
        ])->first();

        if ($checkIsUserScore) {
            $data = collect(Record::where('s_id', $request->id)->select('question_id', 'correct')->get())->map(function ($d) {
                return [
                    'question' => Question::where('id', $d->question_id)->first()->question,
                    'options' => $this->getOption($d->question_id),
                    'answer' => $this->getAnswer($d->question_id),
                    'correct' => $d->correct
                ];
            });

            return response([
                'status' => True,
                'data' => $data,
            ]);
        }

        return response([
            'status' => False,
            'msg' => '不可查看別人成績'
        ]);
    }

    public function getUserAnswerRecord(Request $request)
    {
        $answer_record = collect(User::where('id', '>', 4)->get())->map(function ($d) use ($request) {
            return collect([
                'student_class' => Classname::find($d->class_id)->name,
                'student_number' => $d->account,
                'student_name' => $d->username,
                'record' => collect(Scores::where([
                    ['year_id', '=', $request->year_id],
                    ['user_id', '=', $d->id]
                ])->select('score')->get())->pad(2, '未作答')
            ]);
        });

        return [
            'msg' => '查詢已完成。',
            'data' => $answer_record
        ];
    }

    public function toggleAnswerRecord(Request $request)
    {
        collect(SchoolYear::all())->map(function ($d) {
            $d->update(['isAnswerRecord' => 0]);
        });

        SchoolYear::where('id', $request->id)->update([
            'isAnswerRecord' => !$request->bool
        ]);

        return response([
            'msg' => !$request->bool ? '分數詳細資料已開啟。' : '分數詳細資料已關閉。',
        ]);
    }

    public function toggleExam(Request $request)
    {
        collect(SchoolYear::all())->map(function ($d) {
            $d->update(['isExam' => 0]);
        });

        SchoolYear::where('id', $request->id)->update([
            'isExam' => !$request->bool
        ]);

        return response([
            'msg' => !$request->bool ? '測驗已開啟。' : '測驗已關閉。',
        ]);
    }
}
