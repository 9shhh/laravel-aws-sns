<?php

namespace App\Console;

use App\Reservation;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use AWS;

class Kernel extends ConsoleKernel
{
    private function sendPush($arn, $message)
    {
        // Call AWS SNS Facade
        $sns = AWS::createClient('sns');
        $sns->publish(array(
            'TargetArn' => $arn,
            'MessageStructure' => 'json',
            'Message' => json_encode(array(
                'default' => '',
                'APNS' => json_encode(array(
                    'aps' => array(
                        'alert' => $message,
                    ),
                    // Custom payload parameters can go here
                    'type' => 'individual',
                ))
            ))
        ));
    }

    private function checkNationality($user,$massages)
    {
        // 체크아웃 하려고 하는 유저의 nationality가 대한민국이 아니면, 모두 영어로 푸시
        if ($user->user->nationality->name === '대한민국') {                    // 한국어
            if ($user->user->arn) {                                     // has arn check for user
                $this->sendPush($user->user->arn, $massages[0]);
            }
        } else {                                                        // 영어로
            if ($user->user->arn) {                                     // has arn check for user
                $this->sendPush($user->user->arn, $massages[1]);
            }
        }
    }

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
//        'App\Console\Commands\notifications'
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     *
     */
    protected function schedule(Schedule $schedule)
    {
        // 15분 마다 스케줄링
        $schedule->call(function () {
            // 체크아웃 처리된지 1시간 된 유저에게 리뷰 안내 푸시 보내기
            // 예약테이블 조회 (체크아웃 처리시간이 현재시간 - 1시간 보다 작거나 같은 조건)
            $datas = Reservation::where('actual_check_out', '<=', Carbon::now()->subHour())->get();
            if (!empty($datas)) {
                foreach ($datas as $data) {
                    // 1시간 후 리뷰 작성 요청 푸시 보내기
                    // 체크아웃처리 컬럼데이터가 0일 때만 푸시 보내기
                    if ($data->after_check_out_1hour === 0) {
                        // 체크아웃 컬럼데이터 1로 변경(푸시 보냄을 의미)
                        $data->after_check_out_1hour = 1;
                        $data->save();
                        $massages = ['서비스는 어떠셨나요? 호스트에게 리뷰를 남겨주세요.','How was the service? Please leave a comment to the host.'];
                        $this->checkNationality($data,$massages);
                    }
                }
            }

            // 체크인 24시간 전 유저에게 푸시 보내기
            // 예약테이블 조회 (유저의 체크인 예정시간이 현재시간 + 24시간 1보다 작거나 같은 조건)
            $datas2 = Reservation::where('booked_check_in', '<=', Carbon::now()->addHour(24))->get();
            if (!empty($datas2)) {
                foreach ($datas2 as $data2) {
                    // 24시간 후 체크인 예정 푸시 보내기
                    // 체크인 컬럼데이터가 0일 때만 푸시 보내기
                    if ($data2->before_check_in_24hour === 0) {
                        // 체크인 컬럼데이터 1로 변경(푸시 보냄을 의미)
                        $data2->before_check_in_24hour = 1;
                        $data2->save();
                        $massages = ['체크인 시간이 하루 남았습니다. 예약 내역을 확인해주세요.','You have 1 day left before check in. Please check the reservation you made.'];
                        $this->checkNationality($data2,$massages);
                    }
                }
            }

        })->everyFifteenMinutes();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
