<?php
/**
 * DHTMLX Scheduler Events Parser
 *
 * Parsing events table to determine free timeslots in the schedule
 * 
 * Table sample (typical DHTMLX Scheduler with recurrent event):
 * =================================================================================================================================================
 * | event_id | section_id |      start_date     |      end_date       | event_name | status_id |      rec_type         | event_length | event_pid |
 * -------------------------------------------------------------------------------------------------------------------------------------------------
 * |    30    |     1      | 2010-08-02 12:00:00 | 9999-02-01 00:00:00 |   dinner   |     3     | week_1___1,2,3,4,5#no |     2400     |     0     |
 * =================================================================================================================================================
 *
 * @author Andrei Chugunov
 *
 * @param Model
 * @return array
 *
 */
class DhtmlxScheduler
{
    private static $scheduleHorizon = 1; // View perspective in full weeks
    private $freeSlots = array();
    private $model;
    private $period;
    private $startHour = '10:00:00'; // Can be overriding from config
    private $endHour = '19:00:00';

    public function __construct($model)
    {
        $this->model = $model;
        $this->period = self::getPeriod(); // Get period for display
        // @todo params existing check
        $this->startHour = substr('0' . Yii::app()->params['startHour'], -2) . ':00:00';
        $this->endHour = substr('0' . Yii::app()->params['endHour'], -2) . ':00:00';
    }

    public function getSlots()
    {
        $daysCount = 0;
        foreach ($this->period as $day) {
            $dayIndex = 'd-' . $daysCount;
            $this->freeSlots[$dayIndex] = array();

            $dayStart = DateTime::createFromFormat('Y-m-d H:i:s', $day->format("Y-m-d") . ' ' . $this->startHour);
            $dayEnd = DateTime::createFromFormat('Y-m-d H:i:s', $day->format("Y-m-d") . ' ' . $this->endHour);
            // @todo SQL in loop
            $modelEventsArray = $this->modelEvents($day);
            // @todo SQL in loop
            $modelRecChildArray = $this->modelRecChild($day);

            if (count($modelEventsArray) > 0) { // @todo Check if not
                foreach ($modelEventsArray as $modelEvent) {
                    $eventStart = '';
                    $eventEnd = '';
                    $recArray = array();
                    if ($modelEvent->rec_type) { // Is recurring event
                        $recStartTime = new DateTime( $modelEvent->start_date );
                        $recEndTime = new DateTime( $modelEvent->start_date );
                        $recEndTime->modify( '+' . $modelEvent->event_length . 'seconds' );
                        $recArrayFull = explode( '#', $modelEvent->rec_type );
                        $recArray = explode( '_', $recArrayFull[0] );
                        switch ($recArray[0]) {
                            case 'week':
                                if ($recArray[4]) { // Days of week
                                    $recWeekDays = explode(',', $recArray[4]);
                                    foreach ($recWeekDays as $recDay) {
                                        if ($recDay === $day->format("w")) { // If match current day
                                            $eventStart = DateTime::createFromFormat('Y-m-d H:i:s', $day->format("Y-m-d") .
                                                    ' ' . $recStartTime->format('H:i:s'));
                                            $eventEnd = DateTime::createFromFormat('Y-m-d H:i:s', $day->format("Y-m-d") .
                                                    ' ' . $recEndTime->format('H:i:s'));
                                            foreach ($modelRecChildArray as $childRec) {
                                                if ($childRec->event_pid === $modelEvent->event_id) { // Recurring modifier match parent
                                                    if (!$childRec->rec_type) {
                                                        $eventStart = DateTime::createFromFormat('Y-m-d H:i:s', $childRec->start_date);
                                                        $eventEnd = DateTime::createFromFormat('Y-m-d H:i:s', $childRec->end_date);
                                                        break;
                                                    } elseif ($childRec->rec_type === 'none') { // Child deleting parent
                                                        $eventStart = null;
                                                        $eventEnd = null;
                                                        break;
                                                    }
                                                }
                                            }
                                            break;
                                        }
                                    }
                                }
                                break;
                            case 'year':
                                // OMG!!!
                                break;
                            case 'day':
                                // OMG!!!
                                break;
                        }
                    } else { // Is ordinary event
                        $eventStart = new DateTime($modelEvent->start_date);
                        $eventEnd = new DateTime($modelEvent->end_date);
                    }

                    if ($eventStart && $eventEnd) {
                        $eventStart = ($dayStart < $eventStart) ? $eventStart : $dayStart; // If event start earlier than day start
                        $eventEnd = ($dayEnd > $eventEnd) ? $eventEnd : $dayEnd; // If event end later than day end
                        $eventDiff = $dayStart->diff($eventStart);
                        $eventDuration = $eventStart->diff($eventEnd);
                        $eventDurationInMinutes = $eventDuration->format('%H%') * 60 + $eventDuration->format('%i%');
                        $eventStartInMinutes = $eventDiff->format('%H%') * 60 + $eventDiff->format('%i%');
                        //Yii::log( '-----------------------------------: ' . $eventStartInMinutes . '-------' . $eventDurationInMinutes);

                        $this->freeSlots[$dayIndex][] = array(
                            'start' => $eventStartInMinutes,
                            'duration' => $eventDurationInMinutes,
                        );
                    }
                }
            }

            usort($this->freeSlots[$dayIndex], function ($a, $b) { return strnatcmp($a['start'], $b['start']); });
            $daysCount++;
        }

        $this->freeSlots = $this->reverseSlots($this->freeSlots);

        $toSend = array(
            'service_type' => $this->model->service_type,
            'step' => $this->model->serviceType->step,
            'price' => $this->model->price,
            'duration' => $this->model->duration,
            'freeSlots' => $this->freeSlots,
        );

        return $toSend;
    }

    /**
     * Reverses busy timeslots to free timeslots and applying service time ranges
     * @param array Positive slots array
     * @return array Negative slots array
     */
    private function reverseSlots($slotsArray)
    {
        $arMins = $this->getMinutes();
        $arRecersed = array();
        $dayCount = 0;
        foreach ($slotsArray as $slotsDay) {
            $strTempDay = '0-';
            foreach ($slotsDay as $slotsEvent) {
                $strTempDay .= $slotsEvent['start'] . ',' . ($slotsEvent['start'] + $slotsEvent['duration']) . '-';
            }
            $strTempDay .= $arMins['dayEnd'];

            $arTempDay = explode(',', $strTempDay);
            $arDay = array();
            foreach ($arTempDay as $key => $value) {
                $start = substr($value, 0, strpos($value, '-'));
                $end = substr($value, strpos($value, '-') + 1);
                if ($end <= $arMins['serviceStart'] || $start >= $arMins['serviceEnd']) {
                    continue;
                }
                $start = ($start < $arMins['serviceStart']) ? $arMins['serviceStart'] : $start;
                $end = ($end > $arMins['serviceEnd']) ? $arMins['serviceEnd'] : $end;
                if ($start !== $end) {
                    $arDay[] = array(
                        'start' => (int)$start,
                        'duration' => (int)($end - $start),
                    );
                }
            }

            $arRecersed['d-' . $dayCount] = $arDay;
            $dayCount++;
        }
        return $arRecersed;
    }

    /**
     * Set time ranges in minutes
	 *
     * @return array
     */
    private function getMinutes()
    {
        $arMins = array();
        $tmpDayStart = DateTime::createFromFormat('Y-m-d H:i:s', '2000-01-01 ' . $this->startHour);
        $tmpDayEnd = DateTime::createFromFormat('Y-m-d H:i:s', '2000-01-01 ' . $this->endHour);
        $tmpDayDiff = $tmpDayStart->diff($tmpDayEnd);

        $arMins['dayEnd'] = $tmpDayDiff->format('%r%H%') * 60 + $tmpDayDiff->format('%r%i%');

        $serviceBegin = DateTime::createFromFormat('Y-m-d H:i:s', '2000-01-01 ' . $this->model->begin . ':00');
        $serviceEnd = DateTime::createFromFormat('Y-m-d H:i:s', '2000-01-01 ' . $this->model->end . ':00');
        $serviceBegin = ($serviceBegin > $tmpDayStart) ? $serviceBegin : $tmpDayStart;
        $serviceEnd = ($serviceEnd < $tmpDayEnd) ? $serviceEnd : $tmpDayEnd;
        $serviceBeginDiff = $tmpDayStart->diff($serviceBegin);
        $serviceEndDiff = $tmpDayStart->diff($serviceEnd);

        $arMins['serviceStart'] = $serviceBeginDiff->format('%r%H%') * 60 + $serviceBeginDiff->format('%r%i%');
        $arMins['serviceEnd'] = $serviceEndDiff->format('%r%H%') * 60 + $serviceEndDiff->format('%r%i%');

        return $arMins;
    }

    /**
     * Get Period object for appointment
	 * 
	 * @return DatePeriod
     */
    public static function getPeriod()
    {
        $currentDay = getdate();
        $daysToEndPeriod = 6 - $currentDay['wday'] + 7 * self::$scheduleHorizon;
        if ($currentDay['wday'] === 0) { // At Sunday show one week less
            $daysToEndPeriod = 6 - $currentDay['wday'] + 7 * (self::$scheduleHorizon - 1);
        }
        $startDate = new DateTime();
        $endDate = new DateTime();
        $endDate = $endDate->modify("+$daysToEndPeriod day");
        $interval = new DateInterval("P1D");
        return new DatePeriod($startDate, $interval , $endDate);
    }
	
	/**
	 * Find all event at for specified day
	 *
	 * @param DateTime $day
	 * @return array
	 */
    private function modelEvents($day)
    {
        $modelEvents = Events::model()->findAll( array(
        'order' => 'start_date',
        'condition' => '(section_id=:section_id AND
                        start_date LIKE :start_date_m AND
                        event_pid = 0)
	                OR
	                    (section_id=:section_id AND
                        event_length > 0 AND
	                    start_date <= :start_date AND
	                    end_date >= :start_date AND
	                    event_pid = 0)',
        'params' => array(
            ':section_id' => $this->model->service_type,
            ':start_date_m' => '%' . $day->format('Y-m-d') . '%',
            ':start_date' => $day->format('Y-m-d H:i:s'),
        ),
        ));

        return $modelEvents;
    }

	/**
	 * Find all children for recurring Event
	 *
	 * @param DateTime $day
	 * @return array
	 */
    private function modelRecChild($day)
    {
        $modelRecChild = Events::model()->findAll(array(
            'order' => 'start_date',
            'condition' => '(section_id=:section_id AND
                            start_date LIKE :start_date_m AND
        	                event_pid > 0)',
            'params' => array(
                ':section_id' => $this->model->service_type,
                ':start_date_m' => '%' . $day->format('Y-m-d') . '%',
            ),
        ));

        return $modelRecChild;
    }
}