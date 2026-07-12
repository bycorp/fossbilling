<?php

declare(strict_types=1);

/**
 * ============================================================
 * Enom Registrar Adapter for FOSSBilling 0.8.x
 * ============================================================
 *
 * Все структуры XML ответов подтверждены реальными вызовами API.
 * 
 * Установка:
 *   Скопируй в: [FOSSBilling]/src/library/Registrar/Adapter/Enom.php
 *   Admin → System → Domain Registration → New Registrar → Enom
 *   Добавь IP сервера: cp.enom.com → Resellers → API → Add/Edit IP
 *
 * API: https://api.enom.com/docs
 * Production: https://reseller.enom.com/interface.asp
 * Sandbox:    https://resellertest.enom.com/interface.asp
 *       created info@by-systems.com
 * SPDX-License-Identifier: Apache-2.0
 * ============================================================
 */

class Registrar_Adapter_Enom extends Registrar_AdapterAbstract
{
    private const API_PROD = 'https://reseller.enom.com/interface.asp';
    private const API_TEST = 'https://resellertest.enom.com/interface.asp';
    private const TIMEOUT  = 30;

    /** @var array<string, mixed> */
    private array $_config = [];

    // =========================================================================
    // Конструктор и конфигурация
    // =========================================================================

    /**
     * @param array<string, mixed> $options
     * @throws Registrar_Exception
     */
    public function __construct(array $options)
    {
        if (empty($options['username'])) {
            throw new Registrar_Exception('Enom: Username (UID) обязателен.');
        }
        if (empty($options['api_token_prod'])) {
            throw new Registrar_Exception('Enom: API Token (боевой, PW) обязателен.');
        }
        $this->_config = $options;
    }

    /**
     * Форма настроек в Admin → System → Domain Registration.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return [
            'label' => 'Enom',
            'form'  => [
                'username'  => [
                    'text',
                    [
                        'label'       => 'Enom Username (UID)',
                        'description' => 'Ваш логин reseller ID из аккаунта Enom (обычно один и тот же для боевого и тестового окружений)',
                    ],
                ],
                'api_token_prod' => [
                    'password',
                    [
                        'label'       => 'Enom API Token — Production (PW)',
                        'description' => 'Боевой токен из cp.enom.com → Resellers → API. Используется, когда "Enable Test Mode" = Нет.',
                    ],
                ],
                'api_token_test' => [
                    'password',
                    [
                        'label'       => 'Enom API Token — Sandbox (PW)',
                        'description' => 'Отдельный токен для resellertest.enom.com (создаётся через cp.enom.com → Resellers → Reseller Test Account). Используется, когда "Enable Test Mode" = Да. Если оставить пустым — будет использован боевой токен (обычно не сработает для sandbox).',
                        'required'    => false,
                    ],
                ],
            ],
        ];
    }

    // =========================================================================
    // 1. Проверка доступности домена
    // =========================================================================

    /**
     * Проверяет доступность домена для регистрации.
     *
     * Команда: Check
     * Реальный успешный ответ:
     *   <RRPCode>210</RRPCode>  — доступен
     *   <RRPCode>211</RRPCode>  — занят
     *   <ErrCount>0</ErrCount>
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $response = $this->call('Check', [
            'SLD' => $sld,
            'TLD' => $tld,
        ]);

        // ИСПРАВЛЕНО: раньше здесь не было проверки ошибок — если Enom
        // возвращал ErrCount>0 (например неверные креды или IP не в
        // белом списке), RRPCode отсутствовал в ответе, и код молча
        // подставлял 211 ("занят"), вместо того чтобы показать реальную
        // причину сбоя. Теперь ошибка API явно всплывает как исключение.
        $this->checkError($response, 'isDomainAvailable');

        // RRPCode находится в корне ответа (подтверждено реальным XML)
        $code = (int)($response['RRPCode'] ?? 211);

        return $code === 210;
    }

    /**
     * Проверяет возможность трансфера домена в Enom.
     * Домен должен быть зарегистрирован (RRPCode=211).
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $response = $this->call('Check', [
            'SLD' => $sld,
            'TLD' => $tld,
        ]);

        // ИСПРАВЛЕНО: то же, что в isDomainAvailable — раньше ошибки API
        // молча приводили к неверному результату вместо явного исключения.
        $this->checkError($response, 'isDomaincanBeTransferred');

        $code = (int)($response['RRPCode'] ?? 210);

        return $code === 211;
    }

    // =========================================================================
    // 2. Регистрация домена
    // =========================================================================

    /**
     * Регистрирует новый домен.
     *
     * Команда: Purchase
     *
     * Реальный успешный ответ (подтверждён тестом):
     *   <OrderID>162930405</OrderID>
     *   <RRPCode>200</RRPCode>
     *   <RRPText>Command completed successfully - 162930405</RRPText>
     *   <ErrCount>0</ErrCount>
     *
     * ВАЖНО: Enom принимает контакт только для Registrant в Purchase.
     * Admin/Tech/Billing можно оставить пустыми — Enom скопирует из Registrant.
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function registerDomain(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        // ИСПРАВЛЕНО: getContactRegistrar(), а не несуществующий getContactRegistrant()
        $contact = $domain->getContactRegistrar();
        $years   = max(1, (int)$domain->getRegistrationPeriod());

        // ВАЖНО: официальная документация Enom утверждает, что UseDNS=default
        // и NS1..NS4 — взаимоисключающие параметры. Мы пробовали сделать их
        // строго взаимоисключающими (см. историю правок), но это привело к
        // реальной ошибке "Validation error. Failed to create order." —
        // то есть фактическое поведение API отличается от документации.
        // Возвращена проверенная практикой комбинация: отправляем оба сразу,
        // именно так была успешно зарегистрирована реальная тестовая
        // регистрация (testsystem11100101.com, подтверждено вживую).
        $params = array_merge(
            [
                'SLD'      => $sld,
                'TLD'      => $tld,
                'NumYears' => $years,
                'UseDNS'   => 'default',
            ],
            $this->buildNsParams($domain),
            $this->buildRegistrantParams($contact)
        );

        $response = $this->call('Purchase', $params);
        $this->checkError($response, 'registerDomain');

        return true;
    }

    // =========================================================================
    // 3. Трансфер домена
    // =========================================================================

    /**
     * Инициирует трансфер домена в Enom.
     *
     * Команда: TP_CreateOrder
     * Требует EPP код: $domain->getEpp()
     * Суффикс "1" для полей: SLD1, TLD1, AuthInfo1, RegistrantFirstName1...
     *
     * ИСПРАВЛЕНО: OrderType должен быть "AutoVerification" (не "transfer") —
     * подтверждено официальной документацией Enom и независимыми открытыми
     * реализациями (Ruby enom-api, PHP NeoBill). Также обязателен параметр
     * DomainCount — без него команда, вероятно, будет отклонена.
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function transferDomain(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $epp     = (string)($domain->getEpp() ?? '');
        // ИСПРАВЛЕНО: getContactRegistrar(), а не несуществующий getContactRegistrant()
        $contact = $domain->getContactRegistrar();
        $years   = max(1, (int)$domain->getRegistrationPeriod());

        $params = array_merge(
            [
                'SLD1'        => $sld,
                'TLD1'        => $tld,
                'AuthInfo1'   => $epp,
                'DomainCount' => '1',
                'OrderType'   => 'AutoVerification',
                'NumYears'    => $years,
            ],
            $this->buildRegistrantParams($contact, suffix: '1')
        );

        $response = $this->call('TP_CreateOrder', $params);
        $this->checkError($response, 'transferDomain');

        return true;
    }

    // =========================================================================
    // 4. Продление домена
    // =========================================================================

    /**
     * Продлевает регистрацию домена.
     *
     * Команда: Extend
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function renewDomain(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $years = max(1, (int)$domain->getRegistrationPeriod());

        $response = $this->call('Extend', [
            'SLD'      => $sld,
            'TLD'      => $tld,
            'NumYears' => $years,
        ]);

        $this->checkError($response, 'renewDomain');

        return true;
    }

    // =========================================================================
    // 5. Информация о домене
    // =========================================================================

    /**
     * Возвращает детали домена.
     *
     * Команды: GetDomainInfo + GetRegLock
     *
     * Реальная XML структура GetDomainInfo (подтверждена тестом):
     *
     * <GetDomainInfo>
     *   <domainname sld="bysystemtest1" tld="com">bysystemtest1.com</domainname>
     *   <status>
     *     <expiration>6/26/2027 6:57:00 PM</expiration>
     *     <registrationstatus>Registered</registrationstatus>
     *   </status>
     *   <services>
     *     <entry name="dnsserver">
     *       <configuration type="dns">
     *         <dns>ns1.enom.com</dns>
     *         <dns>ns2.enom.com</dns>
     *       </configuration>
     *     </entry>
     *     <entry name="whoispublicity">
     *       <whoispublicity>
     *         <enabled>False</enabled>
     *       </whoispublicity>
     *     </entry>
     *   </services>
     * </GetDomainInfo>
     *
     * Реальная XML структура GetRegLock (подтверждена тестом):
     *   <reg-lock>1</reg-lock>      GetRegLock: заблокирован
     *   <reg-lock>0</reg-lock>      GetRegLock: разблокирован
     *   <reg-lock>ACTIVE</reg-lock> SetRegLock ответ: заблокирован
     *
     * @param Registrar_Domain $domain
     * @return Registrar_Domain
     * @throws Registrar_Exception
     */
    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        // --- GetDomainInfo ---
        $infoResp = $this->call('GetDomainInfo', [
            'SLD' => $sld,
            'TLD' => $tld,
        ]);
        $this->checkError($infoResp, 'getDomainDetails');

        // Данные вложены в <GetDomainInfo> (подтверждено реальным XML)
        $info = $infoResp['GetDomainInfo'] ?? [];

        // --- Дата истечения ---
        // Реальное поле: GetDomainInfo → status → expiration
        // Формат: "6/26/2027 6:57:00 PM"
        $expiry = $info['status']['expiration'] ?? null;
        if (!empty($expiry)) {
            $ts = strtotime((string)$expiry);
            if ($ts !== false && $ts > 0) {
                $domain->setExpirationTime($ts);
            }
        }

        // --- Nameservers ---
        // Реальная структура: GetDomainInfo → services → entry[name=dnsserver]
        //                     → configuration → dns (строка или массив строк)
        $nsValues = $this->extractNs($info);
        if (!empty($nsValues[0])) {
            $domain->setNs1($nsValues[0]);
        }
        if (!empty($nsValues[1])) {
            $domain->setNs2($nsValues[1]);
        }
        if (!empty($nsValues[2])) {
            $domain->setNs3($nsValues[2]);
        }
        if (!empty($nsValues[3])) {
            $domain->setNs4($nsValues[3]);
        }

        // --- Privacy (Whois Publicity) ---
        // Реальная структура: GetDomainInfo → services → entry[name=whoispublicity]
        //                     → whoispublicity → enabled ("True" / "False")
        $privacyEnabled = $this->extractPrivacy($info);
        $domain->setPrivacyEnabled($privacyEnabled);

        // --- Статус блокировки ---
        // GetRegLock → reg-lock (1=locked, 0=unlocked) — в корне ответа
        $lockResp = $this->call('GetRegLock', [
            'SLD' => $sld,
            'TLD' => $tld,
        ]);
        // Реальные значения reg-lock (подтверждено реальными тестами API):
        // GetRegLock success:          <reg-lock>1</reg-lock>      = locked
        //                              <reg-lock>0</reg-lock>      = unlocked
        // SetRegLock(unlock=0) ответ:  <reg-lock>ACTIVE</reg-lock> = locked
        // SetRegLock(unlock=1) ответ:  <reg-lock>0</reg-lock>      = unlocked
        $regLockRaw = strtolower(trim((string)($lockResp['reg-lock'] ?? '0')));
        $isLocked   = ($regLockRaw === 'active' || $regLockRaw === '1');
        $domain->setLocked($isLocked);

        return $domain;
    }

    // =========================================================================
    // 6. Удаление домена
    // =========================================================================

    /**
     * Удаляет домен.
     *
     * Команда: DeleteRegistration
     * Не все TLD поддерживают удаление.
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $response = $this->call('DeleteRegistration', [
            'SLD' => $sld,
            'TLD' => $tld,
        ]);

        $this->checkError($response, 'deleteDomain');

        return true;
    }

    // =========================================================================
    // 7. EPP / Auth код
    // =========================================================================

    /**
     * Получает EPP (auth) код для трансфера домена наружу.
     *
     * Команда: SynchAuthInfo — отправляет EPP на email регистранта.
     *
     * ВАЖНО: Enom НЕ возвращает EPP код через API напрямую.
     * SynchAuthInfo отправляет его на email. Это ограничение Enom API.
     *
     * @param Registrar_Domain $domain
     * @return string
     * @throws Registrar_Exception
     */
    public function getEpp(Registrar_Domain $domain): string
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $response = $this->call('SynchAuthInfo', [
            'SLD'              => $sld,
            'TLD'              => $tld,
            'EmailEPP'         => 'True',
            'RunSynchAutoInfo' => 'True',
        ]);

        // SynchAuthInfo реальный ответ (подтверждён тестом):
        // ErrCount=0 даже при ошибке отправки email — это нормально.
        // Поля ErrString/ErrSource/EPPEmailMessage — информационные, не критичные.
        // Enom физически не возвращает EPP через API — только на email.
        // Логируем если email не отправился но не бросаем исключение.
        if (!empty($response['ErrString'])) {
            $this->getLog()->warning(
                'Enom SynchAuthInfo: ' . (string)$response['EPPEmailMessage']
                . ' | ErrString: ' . (string)$response['ErrString']
            );
        }

        // ErrCount > 0 — реальная ошибка API (например домен не найден)
        $this->checkError($response, 'getEpp');

        // Enom отправляет EPP на email регистранта — это стандартное поведение.
        return 'EPP код отправлен на email регистранта домена. Проверьте почту.';
    }

    // =========================================================================
    // 8. Nameservers
    // =========================================================================

    /**
     * Обновляет nameservers домена.
     *
     * Команда: ModifyNS
     * Параметры: SLD, TLD, NS1, NS2[, NS3, NS4, NS5]
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function modifyNs(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $params = array_merge(
            ['SLD' => $sld, 'TLD' => $tld],
            $this->buildNsParams($domain)
        );

        $response = $this->call('ModifyNS', $params);
        $this->checkError($response, 'modifyNs');

        return true;
    }

    // =========================================================================
    // 9. Контакты
    // =========================================================================

    /**
     * Обновляет контактную информацию домена.
     *
     * Команда: Contacts
     * Поля: RegistrantFirstName, RegistrantLastName, RegistrantEmailAddress...
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function modifyContact(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        // ИСПРАВЛЕНО: getContactRegistrar(), а не несуществующий getContactRegistrant()
        $contact = $domain->getContactRegistrar();

        $params = array_merge(
            ['SLD' => $sld, 'TLD' => $tld],
            $this->buildRegistrantParams($contact)
        );

        $response = $this->call('Contacts', $params);
        $this->checkError($response, 'modifyContact');

        return true;
    }

    // =========================================================================
    // 10. Privacy Protection
    // =========================================================================

    /**
     * Включает Whois Privacy (Whois Guard).
     *
     * Команда: EnableServices (service=wpps)
     *
     * ВАЖНО: Whois Guard в Enom — платная услуга.
     * Если ещё не куплена — сначала нужен PurchaseServices.
     * Этот метод только включает/выключает уже купленный Whois Guard.
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        // Правильная команда Enom для включения Whois Privacy (ID Protect):
        // EnableServices с service=wpps (Whois Privacy Protection Service)
        // Подтверждено документацией:
        // https://cp.enom.com/APICommandCatalog/API%20topics/api_EnableServices.htm
        // ВАЖНО: Whois Guard в Enom платный — сначала нужен PurchaseServices.
        // EnableServices включает уже купленный сервис.
        $response = $this->call('EnableServices', [
            'SLD'     => $sld,
            'TLD'     => $tld,
            'service' => 'wpps',
        ]);

        // Реальный ответ (подтверждён тестом):
        // Если уже включён: ErrCount=1, Err1="Unable to activate service",
        //                   Reason="This service is already enabled"
        // Это НЕ ошибка — просто уже активен, считаем успехом.
        if ((int)($response['ErrCount'] ?? 0) === 1) {
            $reason = strtolower((string)($response['Reason'] ?? ''));
            if (str_contains($reason, 'already enabled')) {
                return true; // Уже включён — ок
            }
        }

        $this->checkError($response, 'enablePrivacyProtection');

        return true;
    }

    /**
     * Выключает Whois Privacy.
     *
     * Команда: DisableServices (service=wpps)
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        // Правильная команда Enom для выключения Whois Privacy (ID Protect):
        // DisableServices с service=wpps
        // Подтверждено документацией:
        // https://cp.enom.com/APICommandCatalog/API%20topics/api_DisableServices.htm
        $response = $this->call('DisableServices', [
            'SLD'     => $sld,
            'TLD'     => $tld,
            'service' => 'wpps',
        ]);

        // Аналогично EnableServices — если уже выключен, считаем успехом.
        if ((int)($response['ErrCount'] ?? 0) === 1) {
            $reason = strtolower((string)($response['Reason'] ?? ''));
            if (str_contains($reason, 'already disabled') || str_contains($reason, 'already')) {
                return true;
            }
        }

        $this->checkError($response, 'disablePrivacyProtection');

        return true;
    }

    // =========================================================================
    // 11. Блокировка домена
    // =========================================================================

    /**
     * Блокирует домен (защита от трансфера).
     *
     * Команда: SetRegLock с UnlockRegistrar=0
     * Реальный ответ: <reg-lock>ACTIVE</reg-lock>, <ErrCount>0</ErrCount>
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function lock(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $response = $this->call('SetRegLock', [
            'SLD'             => $sld,
            'TLD'             => $tld,
            'UnlockRegistrar' => '0',
        ]);

        $this->checkError($response, 'lock');

        return true;
    }

    /**
     * Разблокирует домен (разрешает трансфер).
     *
     * Команда: SetRegLock с UnlockRegistrar=1
     * Реальный ответ: <reg-lock>0</reg-lock>, <ErrCount>0</ErrCount> (UnlockRegistrar=1)
     *
     * @param Registrar_Domain $domain
     * @return bool
     * @throws Registrar_Exception
     */
    public function unlock(Registrar_Domain $domain): bool
    {
        [$sld, $tld] = $this->parseDomain($domain->getName());

        $response = $this->call('SetRegLock', [
            'SLD'             => $sld,
            'TLD'             => $tld,
            'UnlockRegistrar' => '1',
        ]);

        $this->checkError($response, 'unlock');

        return true;
    }

    // =========================================================================
    // Приватные методы — API вызов
    // =========================================================================

    /**
     * Выполняет GET запрос к Enom API и возвращает разобранный XML как массив.
     *
     * Enom API: HTTP GET с query string параметрами, ответ в XML.
     *
     * Безопасность:
     * - API Token (PW) НЕ логируется
     * - Используется HTTPS
     * - Таймаут 30 секунд
     *
     * @param string               $command Имя команды Enom (регистронезависимо)
     * @param array<string, mixed> $params  Параметры запроса
     * @return array<string, mixed>
     * @throws Registrar_Exception
     */
    private function call(string $command, array $params = []): array
    {
        // Используем только штатный переключатель FOSSBilling ($this->_testMode,
        // управляется полем "Enable Test Mode" в настройках регистратора).
        // Токен выбирается соответствующий окружению: боевой и тестовый токены
        // у Enom — это РАЗНЫЕ, несовместимые между собой значения.
        $baseUrl = $this->_testMode ? self::API_TEST : self::API_PROD;

        $token = $this->_testMode
            ? (!empty($this->_config['api_token_test']) ? $this->_config['api_token_test'] : $this->_config['api_token_prod'])
            : $this->_config['api_token_prod'];

        $query = array_merge(
            [
                'UID'          => $this->_config['username'],
                'PW'           => $token,
                'COMMAND'      => strtolower($command),
                'responsetype' => 'XML',
            ],
            $params
        );

        $url = $baseUrl . '?' . http_build_query($query);

        // Безопасное логирование — без токена
        $safeParams = array_merge($query, ['PW' => '***']);
        $this->getLog()->info(
            sprintf('Enom → %s [%s.%s]', strtoupper($command), $params['SLD'] ?? '', $params['TLD'] ?? '')
        );

        try {
            $httpClient = $this->getHttpClient();
            $response   = $httpClient->request('GET', $url, ['timeout' => self::TIMEOUT]);
            $body       = $response->getContent(throw: true);
        } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
            throw new Registrar_Exception('Enom: Ошибка подключения — ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new Registrar_Exception('Enom: HTTP ошибка — ' . $e->getMessage());
        }

        if (empty(trim($body))) {
            throw new Registrar_Exception("Enom: Пустой ответ на команду {$command}.");
        }

        return $this->parseXml($body, $command);
    }

    /**
     * Разбирает XML ответ Enom в массив PHP.
     *
     * Все ответы обёрнуты в <interface-response>.
     * Конвертируем SimpleXML → JSON → array.
     *
     * @param string $xml
     * @param string $command
     * @return array<string, mixed>
     * @throws Registrar_Exception
     */
    private function parseXml(string $xml, string $command): array
    {
        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);

        if ($obj === false) {
            $errs = libxml_get_errors();
            libxml_clear_errors();
            $msgs = implode('; ', array_map(fn($e) => trim($e->message), $errs));
            throw new Registrar_Exception("Enom: XML parse error ({$command}): {$msgs}");
        }

        libxml_clear_errors();

        $json  = json_encode($obj);
        $array = json_decode((string)$json, true);

        if (!is_array($array)) {
            throw new Registrar_Exception("Enom: Не удалось декодировать XML ({$command}).");
        }

        return $array;
    }

    /**
     * Проверяет ответ на ошибки.
     *
     * Реальная структура ошибок Enom (подтверждена тестами):
     *
     * <ErrCount>1</ErrCount>
     * <errors>
     *   <Err1>текст ошибки</Err1>
     * </errors>
     * <responses>
     *   <response>
     *     <ResponseNumber>713254</ResponseNumber>
     *     <ResponseString>Policy error; unauthorized</ResponseString>
     *   </response>
     * </responses>
     *
     * @param array<string, mixed> $response
     * @param string               $method
     * @throws Registrar_Exception
     */
    private function checkError(array $response, string $method): void
    {
        $errCount = (int)($response['ErrCount'] ?? 0);

        if ($errCount === 0) {
            return;
        }

        $errors = [];

        // Реальная структура: $response['errors']['Err1'] (подтверждено тестами)
        $errBlock = $response['errors'] ?? [];
        for ($i = 1; $i <= $errCount; $i++) {
            $msg = $errBlock["Err{$i}"] ?? $response["Err{$i}"] ?? null;
            if (!empty($msg)) {
                $errors[] = trim((string)$msg);
            }
        }

        // Резервно — из <responses><response><ResponseString>
        if (empty($errors)) {
            $resp = $response['responses']['response'] ?? [];
            if (isset($resp['ResponseString'])) {
                // Один response
                $errors[] = trim((string)$resp['ResponseString']);
            } elseif (is_array($resp)) {
                // Массив responses
                foreach ($resp as $r) {
                    if (!empty($r['ResponseString'])) {
                        $errors[] = trim((string)$r['ResponseString']);
                    }
                }
            }
        }

        $text = !empty($errors) ? implode('; ', $errors) : "ErrCount={$errCount}";

        $this->getLog()->err("Enom {$method}: {$text}");

        throw new Registrar_Exception("Enom ({$method}): {$text}");
    }

    // =========================================================================
    // Приватные методы — вспомогательные
    // =========================================================================

    /**
     * Разбивает FQDN на SLD и TLD, с конвертацией в punycode для IDN
     * (кириллица и другие не-ASCII скрипты) — подтверждено живым тестом
     * через Enom API: "тест.com" не принимается сырым UTF-8 (RRPCode=828,
     * "Domain contains illegal characters"), но отлично работает в виде
     * "xn--e1aybc.com" (Enom сам возвращает обратно nativeSLD с исходным
     * написанием). Конвертация применяется универсально для любого TLD —
     * если конкретная зона не поддерживает переданный скрипт, Enom вернёт
     * понятную ошибку через обычный RRPCode/ErrCount, а не сломается молча.
     *
     * "example.com"    → ["example", "com"]
     * "example.co.uk"  → ["example", "co.uk"]
     * "тест.com"       → ["xn--e1aybc", "com"]
     *
     * @param string $fqdn
     * @return array{string, string}
     * @throws Registrar_Exception
     */
    private function parseDomain(string $fqdn): array
    {
        $fqdn = trim($fqdn);

        // IDN (кириллица и другие не-ASCII скрипты) — конвертируем каждую
        // метку домена в punycode отдельно, чтобы TLD (обычно латиница)
        // не портился при конвертации, если он вдруг тоже не-ASCII.
        if (preg_match('/[^\x00-\x7F]/', $fqdn)) {
            if (!function_exists('idn_to_ascii')) {
                throw new Registrar_Exception('Enom: PHP intl extension (idn_to_ascii) не установлено, невозможно обработать IDN-домен.');
            }

            $labels = explode('.', $fqdn);
            foreach ($labels as &$label) {
                if (preg_match('/[^\x00-\x7F]/', $label)) {
                    $converted = idn_to_ascii($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                    if ($converted === false) {
                        throw new Registrar_Exception("Enom: не удалось сконвертировать IDN-метку '{$label}' в punycode.");
                    }
                    $label = $converted;
                }
            }
            unset($label);
            $fqdn = implode('.', $labels);

            $this->getLog()->debug('Enom: IDN домен сконвертирован в punycode: ' . $fqdn);
        }

        $fqdn  = strtolower($fqdn);
        $parts = explode('.', $fqdn, 2);

        if (count($parts) < 2 || empty($parts[0]) || empty($parts[1])) {
            throw new Registrar_Exception("Enom: Некорректный домен: '{$fqdn}'");
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Строит параметры NS для Enom API.
     * Enom принимает: NS1, NS2, NS3, NS4, NS5
     *
     * @param Registrar_Domain $domain
     * @return array<string, string>
     */
    private function buildNsParams(Registrar_Domain $domain): array
    {
        $nameservers = array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]);

        $result = [];
        $i      = 1;
        foreach ($nameservers as $ns) {
            $ns = trim((string)$ns);
            if (!empty($ns)) {
                $result["NS{$i}"] = $ns;
                $i++;
            }
        }

        return $result;
    }

    /**
     * Строит параметры контакта для Enom API (Purchase / Contacts).
     *
     * Реальные поля из документации Purchase (подтверждено тестом):
     *   RegistrantFirstName, RegistrantLastName, RegistrantAddress1,
     *   RegistrantCity, RegistrantStateProvince, RegistrantStateProvinceChoice,
     *   RegistrantPostalCode, RegistrantCountry, RegistrantPhone,
     *   RegistrantEmailAddress, RegistrantOrganizationName
     *
     * Для TP_CreateOrder (трансфер) — суффикс "1": RegistrantFirstName1...
     *
     * @param Registrar_Domain_Contact|null $contact
     * @param string                        $suffix  "" или "1" для трансфера
     * @return array<string, string>
     */
    private function buildRegistrantParams(?Registrar_Domain_Contact $contact, string $suffix = ''): array
    {
        if ($contact === null) {
            return [];
        }

        // Разделяем полное имя на First/Last
        $fullName  = trim((string)$contact->getName());
        $parts     = preg_split('/\s+/', $fullName, 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? $firstName;

        // Телефон в формате Enom: +CountryCode.LocalNumber
        $phone = $this->formatPhone(
            (string)($contact->getTelCc() ?? '1'),
            (string)($contact->getTel()   ?? '')
        );

        // Страна — ISO 2-буквенный код (US, RU, DE...)
        $country = strtoupper(substr(trim((string)$contact->getCountry()), 0, 2));

        $fields = [
            'FirstName'             => $firstName,
            'LastName'              => $lastName,
            'OrganizationName'      => trim((string)$contact->getCompany()),
            'Address1'              => trim((string)$contact->getAddress1()),
            'Address2'              => trim((string)$contact->getAddress2()),
            'City'                  => trim((string)$contact->getCity()),
            'StateProvince'         => trim((string)$contact->getState()),
            'StateProvinceChoice'   => 'S',
            'PostalCode'            => trim((string)$contact->getZip()),
            'Country'               => $country,
            'Phone'                 => $phone,
            'EmailAddress'          => trim((string)$contact->getEmail()),
        ];

        // Применяем для всех 4 типов контактов
        $prefixes = ['Registrant', 'Admin', 'Tech', 'AuxBilling'];
        $params   = [];

        foreach ($prefixes as $prefix) {
            foreach ($fields as $field => $value) {
                $params["{$prefix}{$field}{$suffix}"] = $value;
            }
        }

        return $params;
    }

    /**
     * Форматирует телефон в формат Enom: +CC.LocalNumber
     *
     * +1.5555555555  (US)
     * +7.9161234567  (RU)
     *
     * @param string $cc  Код страны
     * @param string $tel Локальный номер
     * @return string
     */
    private function formatPhone(string $cc, string $tel): string
    {
        $cc  = preg_replace('/\D/', '', $cc)  ?: '1';
        $tel = preg_replace('/\D/', '', $tel) ?: '';

        return empty($tel) ? '' : "+{$cc}.{$tel}";
    }

    /**
     * Извлекает nameservers из ответа GetDomainInfo.
     *
     * Реальная XML структура (подтверждена тестом):
     *
     * GetDomainInfo → services → entry[name=dnsserver] → configuration → dns
     *
     * После json_decode simplexml структура:
     * $info['services']['entry'] — массив entry или один entry
     * Каждый entry имеет атрибут '@attributes']['name']
     * entry с name=dnsserver содержит ['configuration']['dns']
     * dns — строка (один NS) или массив строк
     *
     * @param array<string, mixed> $info GetDomainInfo блок
     * @return string[]
     */
    private function extractNs(array $info): array
    {
        $ns      = [];
        $entries = $info['services']['entry'] ?? [];

        if (empty($entries)) {
            return $ns;
        }

        // Если один entry — оборачиваем в массив
        if (isset($entries['@attributes'])) {
            $entries = [$entries];
        }

        foreach ($entries as $entry) {
            $name = $entry['@attributes']['name'] ?? '';

            if ($name !== 'dnsserver') {
                continue;
            }

            // configuration → dns (строка или массив)
            $dns = $entry['configuration']['dns'] ?? null;

            if (is_string($dns) && !empty($dns)) {
                $ns[] = $dns;
            } elseif (is_array($dns)) {
                foreach ($dns as $val) {
                    if (is_string($val) && !empty($val)) {
                        $ns[] = $val;
                    }
                }
            }

            break; // Нашли dnsserver — выходим
        }

        return array_values(array_filter($ns));
    }

    /**
     * Извлекает статус Whois Privacy из ответа GetDomainInfo.
     *
     * Реальная XML структура (подтверждена тестом):
     *
     * GetDomainInfo → services → entry[name=whoispublicity]
     *   → whoispublicity → enabled ("True" / "False")
     *
     * @param array<string, mixed> $info GetDomainInfo блок
     * @return bool
     */
    private function extractPrivacy(array $info): bool
    {
        $entries = $info['services']['entry'] ?? [];

        if (empty($entries)) {
            return false;
        }

        if (isset($entries['@attributes'])) {
            $entries = [$entries];
        }

        // Реальная XML структура GetDomainInfo (подтверждена тестом):
        // <entry name="wpps"><service changable="1">1123</service></entry>
        // <entry name="whoispublicity">
        //   <whoispublicity><enabled>False</enabled></whoispublicity>
        // </entry>
        //
        // Статус Privacy в entry name="whoispublicity" → whoispublicity → enabled
        foreach ($entries as $entry) {
            $name = $entry['@attributes']['name'] ?? '';

            if ($name === 'whoispublicity') {
                $enabled = $entry['whoispublicity']['enabled'] ?? 'False';
                return strtolower(trim((string)$enabled)) === 'true';
            }
        }

        return false;
    }
}