<?php

// --- Настройки ---
const BITRIX_WEBHOOK_BASE = 'https://b24.alageum.com/rest/8011/zyck0wdvjwv3wwft/';
const RECIPIENT_BITRIX_IDS = [8011, 7268, 1374, 4407]; //   Кому отправить уведомление
const DAYS_LEFT = 10;
// Поле даты приёма в Bitrix24: объект USER, тип «Дата», код UF_HIRING_DATE (ID поля: 6364).
// Должно быть заполнено в карточке пользователя в Bitrix24.
const HIRING_DATE_FIELDS = ['UF_HIRING_DATE', 'UF_EMPLOYMENT_DATE'];
// true — при запуске вывести все UF_* поля ответа user.get (чтобы узнать код поля на портале)
const DEBUG_UF_FIELDS = false;
// true — для каждого сотрудника вывести все поля user.get с значениями (для отладки)
const DEBUG_USER_ALL_FIELDS = true;
// Испытательный срок = 3 календарных месяца от даты приёма (приём 15.11 → окончание 15.02)
const PROBATION_MONTHS = 3;

function getTodayDate(): string {
    return date('d.m.Y');
}

/** Форматирует значение для вывода в дебаг (массивы и скаляры). */
function debugFormatValue($value): string {
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return (string) $value;
}

/** Для дебага: запрашивает пользователя по ID без select — возвращает все поля (в т.ч. все UF_*). */
function debugFetchFullUser(int $employeeId): ?array {
    $result = bitrixRestGet('user.get.json', [
        'filter[ID]' => $employeeId,
    ]);
    if (!empty($result['error']) || isset($result['error_description'])) {
        return null;
    }
    $list = $result['result'] ?? [];
    return isset($list[0]) ? $list[0] : null;
}

/** Выводит все поля пользователя (массива из user.get) с значениями, включая UF_*. */
function debugPrintUserFields(array $user, int $employeeId): void {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "Все поля пользователя ID={$employeeId} (включая UF_*)\n";
    echo str_repeat('=', 60) . "\n";
    ksort($user);
    foreach ($user as $key => $value) {
        $display = debugFormatValue($value);
        echo sprintf("  %-30s => %s\n", $key, $display);
    }
    echo str_repeat('=', 60) . "\n\n";
}

/**
 * Вычисляет дату окончания испытательного срока: дата приёма + N календарных месяцев.
 * Пример: приём 15.11.2024, 3 месяца → окончание 15.02.2025.
 */
function getProbationEndDate(\DateTime $hiringDate): \DateTime
{
    $end = clone $hiringDate;
    $end->modify('+' . PROBATION_MONTHS . ' months');
    return $end;
}

/**
 * Возвращает пользователей, у которых до окончания испытательного срока осталось заданное число дней.
 * Испытательный срок = 3 календарных месяца от UF_HIRING_DATE (приём 15.11 → окончание 15.02).
 *
 * @param int $daysLeft До окончания испытательного срока осталось столько дней (по умолчанию 10)
 * @return array Массив карточек пользователей Bitrix24 (ID, NAME, LAST_NAME, UF_HIRING_DATE, ...)
 */
function getUsersWithProbationEndingInDays(int $daysLeft = 10): array {
    $today = new \DateTime('today');
    $today->setTime(0, 0, 0);
    $select = 'ID,NAME,LAST_NAME,SECOND_NAME,WORK_POSITION,WORK_DEPARTMENT,UF_DEPARTMENT,' . implode(',', HIRING_DATE_FIELDS);
    $matched = [];
    $start = 0;

    do {
        $result = bitrixRestGet('user.get.json', [
            'filter[ACTIVE]' => true,
            'select' => $select,
            'start' => $start,
        ]);

        if (!empty($result['error']) || isset($result['error_description'])) {
            return [];
        }
        $list = $result['result'] ?? [];
        if (empty($list)) {
            break;
        }

        foreach ($list as $user) {
            $raw = '';
            foreach (HIRING_DATE_FIELDS as $field) {
                $v = $user[$field] ?? null;
                if ($v !== null && $v !== '') {
                    $raw = $v;
                    break;
                }
            }
            if ($raw === '') {
                continue;
            }
            try {
                if (is_numeric($raw)) {
                    $hiringDt = (new \DateTime())->setTimestamp((int) $raw);
                } else {
                    $hiringDt = new \DateTime($raw);
                }
                $hiringDt->setTime(0, 0, 0);
                $probationEnd = getProbationEndDate($hiringDt);
                $daysUntilEnd = (int) round(($probationEnd->getTimestamp() - $today->getTimestamp()) / 86400);
                // Попадание в диапазон "осталось около $daysLeft дней" (допуск ±1 день)
                if ($daysUntilEnd >= $daysLeft - 1 && $daysUntilEnd <= $daysLeft + 1) {
                    $matched[] = $user;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        $start += count($list);
    } while (count($list) >= 50);

    return $matched;
}

/** Кэш названий подразделений: ID => NAME (чтобы не дергать API повторно). */
$GLOBALS['_department_name_cache'] = [];

/**
 * Возвращает название подразделения по ID (через department.get, с кэшем).
 */
function getDepartmentNameById(int $departmentId): string
{
    if ($departmentId <= 0) {
        return '';
    }
    $cache = &$GLOBALS['_department_name_cache'];
    if (isset($cache[$departmentId])) {
        return $cache[$departmentId];
    }
    $result = bitrixRestGet('department.get.json', [
        'filter[ID]' => $departmentId,
    ]);
    $list = $result['result'] ?? [];
    $name = '';
    if (!empty($list) && isset($list[0]['NAME'])) {
        $name = trim($list[0]['NAME']);
    }
    $cache[$departmentId] = $name;
    return $name;
}

/**
 * Преобразует UF_DEPARTMENT (ID или массив ID) в название подразделения.
 * При массиве ID берётся последний — конкретное подразделение сотрудника (нижний уровень),
 * а не родительское (верхний уровень иерархии).
 */
function resolveDepartmentNames($ufDepartment): string
{
    $ids = [];
    if (is_array($ufDepartment)) {
        foreach ($ufDepartment as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        // Несколько отделов: показываем последний = самое «нижнее» подразделение (напр. «Департамент правового управления УК»)
        if (!empty($ids)) {
            $ids = [end($ids)];
        }
    } elseif ($ufDepartment !== null && $ufDepartment !== '') {
        $id = (int) $ufDepartment;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (empty($ids)) {
        return '—';
    }
    $name = getDepartmentNameById($ids[0]);
    return $name !== '' ? $name : 'ID ' . $ids[0];
}

/**
 * Вызов метода Bitrix24 REST API (GET).
 */
function bitrixRestGet(string $method, array $params = []): array
{
    $base = rtrim(BITRIX_WEBHOOK_BASE, '/');
    $url = $base . '/' . $method . '?' . http_build_query($params);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 15,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return ['error' => 'HTTP request failed', 'result' => null];
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : ['error' => 'Invalid JSON', 'result' => null];
}

/**
 * Вызов метода Bitrix24 REST API (POST), например im.notify.
 */
function bitrixRestPost(string $method, array $body): array
{
    $base = rtrim(BITRIX_WEBHOOK_BASE, '/');
    $url = $base . '/' . $method;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($body),
            'timeout' => 15,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return ['error' => 'HTTP request failed', 'result' => null];
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : ['error' => 'Invalid JSON', 'result' => null];
}

/**
 * Получить данные пользователя из Bitrix24 по ID (имя, фамилия, отчество, должность, дата приёма).
 */
function getBitrixUser(int $bitrixUserId): ?array
{
    $select = 'NAME,LAST_NAME,SECOND_NAME,WORK_POSITION,WORK_DEPARTMENT,UF_DEPARTMENT,' . implode(',', HIRING_DATE_FIELDS);
    $result = bitrixRestGet('user.get.json', [
        'filter[ID]' => $bitrixUserId,
        'select' => $select,
    ]);

    if (!empty($result['error']) || isset($result['error_description'])) {
        return null;
    }
    $list = $result['result'] ?? [];
    return isset($list[0]) ? $list[0] : null;
}

/**
 * Отправить уведомление одному пользователю Bitrix24 (im.notify).
 */
function sendBitrixNotify(int $toUserId, string $message): bool
{
    $result = bitrixRestPost('im.notify.json', [
        'to' => (string) $toUserId,
        'message' => $message,
        'type' => 'SYSTEM',
        'system' => 'Y',
    ]);

    if (!empty($result['error']) || isset($result['error_description'])) {
        return false;
    }
    // Bitrix24 возвращает result: true при успехе
    return isset($result['result']) && $result['result'] === true;
}

/**
 * Формирует текст уведомления по карточке пользователя Bitrix24 (массив из user.get).
 */
function buildProbationMessage(array $user, int $employeeBitrixId): string
{
    $name = trim($user['NAME'] ?? '');
    $surname = trim($user['LAST_NAME'] ?? '');
    $position = trim($user['WORK_POSITION'] ?? '');
    // WORK_DEPARTMENT в Bitrix24 часто приходит уже названием («Юридический отдел»); UF_DEPARTMENT — массив ID ([5241])
    $workDeptRaw = $user['WORK_DEPARTMENT'] ?? null;
    if (is_array($workDeptRaw) || (is_string($workDeptRaw) && is_numeric(trim($workDeptRaw)))) {
        $workDepartment = resolveDepartmentNames($workDeptRaw);
    } else {
        $workDepartment = trim((string) $workDeptRaw) ?: '—';
    }
    $department = resolveDepartmentNames($user['UF_DEPARTMENT'] ?? null);

    $hiringDateRaw = '';
    foreach (HIRING_DATE_FIELDS as $field) {
        $v = $user[$field] ?? null;
        if ($v !== null && $v !== '') {
            $hiringDateRaw = $v;
            break;
        }
    }

    $hiringDateStr = '—';
    $probationEndStr = '—';
    if ($hiringDateRaw !== '') {
        try {
            if (is_numeric($hiringDateRaw)) {
                $hiringDate = (new \DateTime())->setTimestamp((int) $hiringDateRaw);
            } else {
                $hiringDate = new \DateTime($hiringDateRaw);
            }
            $hiringDateStr = $hiringDate->format('d.m.Y');
            $probationEnd = getProbationEndDate($hiringDate);
            $probationEndStr = $probationEnd->format('d.m.Y');
        } catch (\Exception $e) {
            $hiringDateStr = (string) $hiringDateRaw;
        }
    }

    $portalHost = parse_url(BITRIX_WEBHOOK_BASE, PHP_URL_SCHEME) . '://' . parse_url(BITRIX_WEBHOOK_BASE, PHP_URL_HOST);
    $profileUrl = $portalHost . '/company/personal/user/' . $employeeBitrixId . '/';

    $separator = "";
    $text = "⚠️ " . $separator . "\n";
    $text .= "[B]Испытательный срок заканчивается через " . DAYS_LEFT . " дней[/B]\n\n";
    $text .= "Сотрудник:\n";
    $text .= "• Профиль: " . $profileUrl . "\n";
    $text .= "• Имя: " . $name . "\n";
    $text .= "• Фамилия: " . $surname . "\n";
    $text .= "• Должность: " . $position . "\n";
    $text .= "• Подразделение (UF_DEPARTMENT): " . $department . "\n";
    $text .= "• Рабочий отдел (WORK_DEPARTMENT): " . $workDepartment . "\n";
    $text .= "• Дата приёма (UF_HIRING_DATE): " . $hiringDateStr . "\n";
    $text .= "[B]Окончание испытательного срока: " . $probationEndStr . "[/B]\n";
    $text .= $separator;
    if ($hiringDateStr === '—') {
        $text .= "\n\nЗаполните дату приёма в карточке пользователя в Bitrix24 (поле UF_HIRING_DATE).";
    }
    return $text;
}

/**
 * Формирует текст уведомления для самого сотрудника о приближающемся окончании испытательного срока.
 */
function buildEmployeeProbationMessage(array $user, int $employeeBitrixId): string
{
    $name = trim($user['NAME'] ?? '');
    $surname = trim($user['LAST_NAME'] ?? '');
    
    $hiringDateRaw = '';
    foreach (HIRING_DATE_FIELDS as $field) {
        $v = $user[$field] ?? null;
        if ($v !== null && $v !== '') {
            $hiringDateRaw = $v;
            break;
        }
    }

    $probationEndStr = '—';
    if ($hiringDateRaw !== '') {
        try {
            if (is_numeric($hiringDateRaw)) {
                $hiringDate = (new \DateTime())->setTimestamp((int) $hiringDateRaw);
            } else {
                $hiringDate = new \DateTime($hiringDateRaw);
            }
            $probationEnd = getProbationEndDate($hiringDate);
            $probationEndStr = $probationEnd->format('d.m.Y');
        } catch (\Exception $e) {
            // Оставляем '—'
        }
    }

    $text = "👋 Здравствуйте" . ($name ? ", " . $name : "") . "!\n\n";
    $text .= "[B]Ваш испытательный срок заканчивается через " . DAYS_LEFT . " дней[/B]\n\n";
    $text .= "Дата окончания испытательного срока: " . $probationEndStr . "\n\n";
    $text .= "Пожалуйста, подготовьтесь к завершению испытательного периода. Если у вас возникнут вопросы, обратитесь к вашему руководителю или в отдел кадров.\n\n";
    $text .= "Желаем успехов в работе! 💼";
    
    return $text;
}

// --- Основная логика: сотрудники с окончанием испытательного срока через DAYS_LEFT дней ---
$employees = getUsersWithProbationEndingInDays(DAYS_LEFT);

if (empty($employees)) {
    echo "Нет сотрудников, у которых испытательный срок заканчивается через " . DAYS_LEFT . " дней.\n";
    exit(0);
}

echo "Найдено сотрудников: " . count($employees) . "\n";

$portalHost = parse_url(BITRIX_WEBHOOK_BASE, PHP_URL_SCHEME) . '://' . parse_url(BITRIX_WEBHOOK_BASE, PHP_URL_HOST);

foreach ($employees as $user) {
    $employeeId = (int) ($user['ID'] ?? 0);
    if ($employeeId <= 0) {
        continue;
    }

    if (DEBUG_UF_FIELDS) {
        $ufKeys = array_filter(array_keys($user), function ($k) { return strpos($k, 'UF_') === 0; });
        echo "Сотрудник ID={$employeeId}, UF_*: " . implode(', ', $ufKeys) . "\n";
    }

    if (DEBUG_USER_ALL_FIELDS) {
        $fullUser = debugFetchFullUser($employeeId);
        debugPrintUserFields($fullUser ?? $user, $employeeId);
    }

    $text = buildProbationMessage($user, $employeeId);

    // Отправка уведомлений HR-менеджерам/администраторам
    foreach (RECIPIENT_BITRIX_IDS as $recipientId) {
        $ok = sendBitrixNotify((int) $recipientId, $text);
        if ($ok) {
            echo "Уведомление отправлено получателю " . $recipientId . " о сотруднике ID=" . $employeeId . "\n";
        } else {
            fwrite(STDERR, "Не удалось отправить уведомление получателю " . $recipientId . " о сотруднике ID=" . $employeeId . "\n");
        }
    }

    // Отправка уведомления самому сотруднику
    $employeeMessage = buildEmployeeProbationMessage($user, $employeeId);
    $ok = sendBitrixNotify($employeeId, $employeeMessage);
    if ($ok) {
        echo "Уведомление отправлено сотруднику ID=" . $employeeId . " о приближающемся окончании испытательного срока\n";
    } else {
        fwrite(STDERR, "Не удалось отправить уведомление сотруднику ID=" . $employeeId . "\n");
    }
}

echo "Готово.\n";
