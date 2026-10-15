# Словарь задач (`post_tag`) — черновик на согласование

Статус: черновик 2026-10-13. 84 термина. Предмет согласования — **формулировки и состав**, не реализация.

Назначение: сквозная ось навигации «какую задачу решает» для всех трёх типов контента — репозиторий, статья, поставщик. Вторая ось (`repo_topic` — разделы MCP / AI-агенты / Agent Skills) остаётся только у репозиториев и в словарь задач не входит.

---

## Принципы

1. **Задача, а не технология.** «Генерация кода», а не `typescript`. Языки, платформы и названия продуктов — отдельные факты-фильтры, см. раздел «Что не входит».
2. **Задача, а не класс инструмента.** `mcp` / `ai-agent` / `agent-skills` — это разделы (`repo_topic`), в словаре их нет: один термин живёт только в одной оси, иначе два URL с одинаковым содержимым.
3. **Широко: бизнес + быт.** ~⅓ терминов сейчас не имеет ни одного репозитория — они заведены под статьи и поставщиков. Это нормально: термин утверждает человек, а не счётчик.
4. **Определение обязательно.** Однострочное определение — не документация, а часть промпта: без него модель матчит по похожести строк, что и дало текущие 3004 тега.
5. **Слаг латиницей**, имя — по-русски. URL читаемые и не меняются при переименовании термина.

---

## 1. Разработка и код

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `code-generation` | Генерация кода | Создание нового кода по описанию задачи | `code-generation`, `ai-coding`, `agentic-coding`, `vibe-coding`, `code-completion` |
| `code-review` | Ревью и аудит кода | Проверка готового кода на ошибки, стиль и уязвимости | `code-review`, `static-analysis`, `pr-review`, `linting` |
| `testing-qa` | Тестирование и QA | Написание и прогон тестов, поиск регрессий | `testing`, `qa`, `e2e`, `test-automation` |
| `debugging` | Отладка и диагностика | Поиск причины сбоя в работающей системе | `debugging`, `troubleshooting`, `error-analysis` |
| `dev-tooling` | Инструменты разработчика | CLI, терминал, IDE, SDK, обвязка рабочего места | `developer-tools`, `devtools`, `cli`, `terminal`, `tui`, `ide`, `sdk`, `harness`, `agent-harness` |
| `code-docs` | Документирование кода | Генерация и поддержка технической документации по коду | `documentation`, `docstring`, `api-docs` |
| `legacy-migration` | Рефакторинг и миграция legacy | Перенос и переписывание старого кода на новый стек | `refactoring`, `migration`, `modernization` |

## 2. Агенты и автоматизация процессов

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `agent-orchestration` | Оркестрация агентов | Координация нескольких агентов или шагов в одной задаче | `agent-orchestration`, `multi-agent`, `orchestration`, `agent-framework`, `agentic-workflow`, `agentic-workflows`, `a2a`, `acp`, `langgraph` |
| `agent-memory` | Память агента | Хранение и переиспользование контекста между сессиями | `agent-memory`, `ai-memory`, `memory`, `long-term-memory`, `llm-memory`, `persistent-memory` |
| `tool-use` | Подключение инструментов и API | Доступ модели к внешним функциям, сервисам и данным | `llm-tools`, `mcp-tools`, `function-calling`, `tool-calling`, `openapi` |
| `workflow-automation` | Автоматизация рабочих процессов | Сквозные цепочки действий вместо ручных операций | `workflow-automation`, `workflow`, `automation`, `low-code`, `no-code` |
| `browser-automation` | Автоматизация браузера и веб-действий | Действия на сайтах от имени пользователя, сбор данных с веба | `browser-automation`, `web-scraping`, `computer-use`, `rpa` |
| `scheduled-agents` | Агенты по расписанию | Регулярный автозапуск без участия человека | `cron`, `scheduler`, `background-agent` |
| `human-in-loop` | Контроль человеком | Согласование и подтверждение шагов перед выполнением | `human-in-the-loop`, `approval-workflow` |

## 3. Знания, поиск, документы

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `rag-search` | Поиск по своим документам (RAG) | Ответы по собственной базе документов, а не по общим знаниям модели | `rag`, `agentic-rag`, `semantic-search`, `vector-search`, `embeddings` |
| `knowledge-base` | База знаний и вики | Ведение и структурирование корпоративных или личных знаний | `knowledge-base`, `knowledge-management`, `wiki`, `second-brain`, `pkm`, `knowledge-graph`, `notes` |
| `document-processing` | Обработка документов | Распознавание, разбор и извлечение полей из файлов и сканов | `document-processing`, `ocr`, `pdf`, `document-parsing` |
| `summarization` | Пересказ и выжимки | Сжатие длинного текста, встречи или переписки до главного | `summarization`, `tldr` |
| `translation` | Перевод | Перевод текста и документов между языками | `translation`, `localization`, `i18n` |
| `transcription` | Расшифровка аудио и видео | Перевод речи в текст с разметкой по говорящим | `transcription`, `speech-to-text`, `stt`, `subtitles` |
| `prompting` | Подготовка контекста и промптов | Проектирование запросов и сжатие контекста под задачу | `prompt-engineering`, `context-engineering`, `prompt-templates` |

## 4. Данные и аналитика

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `data-extraction` | Извлечение и парсинг данных | Получение структурированных данных из неструктурированных источников | `data-extraction`, `parsing`, `structured-output` |
| `data-prep` | Подготовка и очистка данных | Нормализация, дедупликация, приведение к единому виду | `data-cleaning`, `etl`, `data-quality` |
| `bi-reporting` | Отчёты и дашборды | Регулярная отчётность и визуализация показателей | `dashboard`, `bi`, `reporting`, `data-visualization` |
| `db-access` | Работа с базами данных | Запросы к БД на естественном языке, доступ агента к данным | `database`, `sql`, `text-to-sql` |
| `forecasting` | Прогнозирование и планирование | Оценка будущих значений: спрос, загрузка, выручка | `forecasting`, `prediction`, `planning` |
| `anomaly-detection` | Поиск аномалий | Обнаружение отклонений в данных, метриках и поведении | `anomaly-detection`, `outlier-detection`, `fraud-detection` |

## 5. Контент и творчество

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `text-generation` | Генерация текста | Создание текстов: посты, письма, описания, статьи | `text-generation`, `copywriting`, `content-generation` |
| `copy-editing` | Редактура и проверка текста | Правка, стиль, грамотность, приведение к гайдлайну | `proofreading`, `editing`, `grammar` |
| `image-generation` | Генерация и обработка изображений | Создание картинок по описанию, ретушь, удаление фона | `image-generation`, `text-to-image`, `image-editing`, `inpainting` |
| `video-generation` | Генерация и монтаж видео | Создание видео, нарезка, субтитры, озвучка | `video-generation`, `text-to-video`, `video-editing` |
| `audio-music` | Звук, голос и музыка | Синтез речи, озвучка, генерация и обработка музыки | `tts`, `text-to-speech`, `voice-cloning`, `music-generation`, `audio-generation`, `vgm` |
| `design-assets` | Дизайн и макеты | Логотипы, баннеры, UI-макеты, фирменный стиль | `design`, `ui-design`, `logo`, `figma` |
| `presentations` | Презентации и документы | Слайды, коммерческие предложения, отчётные документы | `presentations`, `slides`, `pitch-deck` |
| `seo-content` | SEO и контент для сайтов | Семантика, тексты под поиск, оптимизация страниц | `seo`, `content-marketing` |

## 6. Коммуникации и поддержка клиентов

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `chat-assistants` | Чат-боты и ассистенты | Диалоговый интерфейс к знаниям или сервису | `chatbot`, `chatbots`, `ai-assistant`, `personal-assistant`, `conversational-ai` |
| `customer-support` | Поддержка клиентов | Обработка обращений, тикетов, первая линия | `customer-support`, `helpdesk`, `ticketing` |
| `voice-agents` | Голосовые агенты и телефония | Приём и совершение звонков голосом | `voice-agent`, `voice-ai`, `telephony`, `ivr` |
| `messaging` | Мессенджеры и соцсети | Работа через Telegram, WhatsApp, VK, Slack и соцсети | `telegram-bot`, `whatsapp`, `slack`, `discord`, `smm` |
| `email-assist` | Работа с почтой | Разбор входящих, черновики ответов, рассылки | `email`, `inbox`, `newsletter` |
| `meeting-assist` | Встречи и протоколы | Запись, расшифровка и итоги созвонов с задачами | `meeting-notes`, `minutes` |

## 7. Продажи и маркетинг

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `lead-generation` | Лидогенерация | Поиск и квалификация потенциальных клиентов | `lead-generation`, `prospecting`, `outreach` |
| `crm-automation` | CRM и воронка продаж | Ведение сделок, статусы, follow-up, напоминания | `crm`, `sales-automation`, `pipeline` |
| `ads-automation` | Реклама и кампании | Создание, запуск и оптимизация рекламы | `advertising`, `ppc`, `ad-automation` |
| `market-research` | Исследование рынка и конкурентов | Сбор и анализ данных о рынке, ценах, конкурентах | `market-research`, `competitor-analysis` |
| `pricing-promo` | Ценообразование и промо | Расчёт цен, скидок, акций | `pricing`, `dynamic-pricing` |
| `ecommerce` | Маркетплейсы и интернет-магазины | Карточки товаров, выгрузки, работа на площадках | `ecommerce`, `marketplace`, `woocommerce`, `product-catalog` |

## 8. Финансы, учёт, юридическое

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `accounting` | Бухгалтерия и первичка | Проводки, акты, накладные, сверки | `accounting`, `bookkeeping` |
| `invoicing-payments` | Счета и платежи | Выставление счетов, приём и сверка платежей | `invoicing`, `payments`, `billing` |
| `financial-analysis` | Финансовый анализ и бюджеты | Юнит-экономика, бюджетирование, управленческий учёт | `financial-analysis`, `budgeting`, `fpa` |
| `legal-docs` | Договоры и юридические документы | Подготовка и проверка договоров, правовой анализ | `legal`, `contract-analysis`, `legaltech` |
| `compliance` | Комплаенс и регуляторика | Соответствие требованиям, аудит решений, журнал действий | `compliance`, `ai-governance`, `audit-trail`, `governance` |
| `tax-reporting` | Налоги и отчётность | Расчёт налогов, подготовка и сдача отчётности | `tax`, `tax-reporting` |

## 9. Персонал и обучение

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `recruiting` | Подбор персонала | Скрининг резюме, интервью, оценка кандидатов | `recruiting`, `hiring`, `ats`, `resume-screening` |
| `hr-processes` | HR-процессы и адаптация | Онбординг, кадровые документы, внутренние запросы | `hr`, `onboarding`, `people-ops` |
| `corporate-training` | Корпоративное обучение | Курсы, тренажёры и проверка знаний для сотрудников | `training`, `lms`, `e-learning` |
| `tutoring` | Репетитор и помощь в учёбе | Объяснение материала, разбор задач, подготовка к экзаменам | `tutoring`, `education`, `study-assistant`, `homework` |

## 10. Операции и логистика

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `project-management` | Управление проектами и задачами | Планирование, трекинг, распределение работ | `project-management`, `task-management`, `productivity`, `workspace`, `kanban` |
| `logistics` | Логистика и склад | Маршруты, доставка, учёт запасов | `logistics`, `supply-chain`, `inventory`, `warehouse` |
| `procurement` | Закупки и поставщики | Выбор поставщика, тендеры, сравнение предложений | `procurement`, `sourcing`, `rfp` |
| `scheduling` | Расписание и запись клиентов | Календарь, слоты, бронирование, смены | `scheduling`, `booking`, `calendar`, `shift-planning` |
| `field-service` | Выездные работы и сервис | Заявки, наряды, выездные специалисты | `field-service`, `work-orders`, `dispatch` |

## 11. Личные и бытовые задачи

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `personal-finance` | Личные финансы | Бюджет, траты, накопления, инвест-учёт | `personal-finance`, `budget-tracker`, `expense-tracking` |
| `health-fitness` | Здоровье и спорт | Тренировки, питание, самочувствие, напоминания | `health`, `fitness`, `nutrition`, `wellness` |
| `cooking` | Еда и рецепты | Рецепты, меню, список покупок | `cooking`, `recipes`, `meal-planning` |
| `travel` | Путешествия и поездки | Маршруты, билеты, жильё, планирование поездки | `travel`, `trip-planning`, `itinerary` |
| `smart-home` | Умный дом | Управление устройствами и сценариями в доме | `smart-home`, `home-automation`, `home-assistant`, `iot` |
| `photo-archive` | Фото- и видеоархив | Разбор, поиск и упорядочивание личных снимков | `photo-management`, `photo-organization`, `media-library` |
| `personal-docs` | Личные документы и госуслуги | Заявления, справки, формы, общение с ведомствами | `paperwork`, `forms`, `govtech` |
| `shopping` | Покупки и выбор товаров | Сравнение, подбор, отслеживание цен | `shopping`, `price-tracking`, `product-comparison` |
| `games` | Игры и развлечения | Игры, эмуляция, развлекательные приложения | `game-development`, `gaming`, `emulation`, `emulator`, `retroarch`, `libretro` |

## 12. Инфраструктура и запуск моделей

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `local-models` | Локальный запуск моделей | Запуск модели на своём железе, без внешнего API | `local-llm`, `local-ai`, `on-premise`, `offline-ai` |
| `model-gateway` | Шлюз и маршрутизация моделей | Единая точка доступа к нескольким моделям и провайдерам | `ai-gateway`, `llm-gateway`, `api-gateway`, `mcp-gateway`, `proxy`, `routing` |
| `fine-tuning` | Дообучение и адаптация моделей | Настройка модели под свои данные и задачу | `fine-tuning`, `lora`, `training-pipeline` |
| `deployment` | Развёртывание и эксплуатация | Поставка, запуск и сопровождение решения в проде | `deployment`, `devops`, `cloud-native`, `self-hosting` |
| `cost-optimization` | Оптимизация затрат на модели | Снижение расхода токенов и стоимости запросов | `cost-optimization`, `token-optimization`, `caching` |
| `observability` | Наблюдаемость и трейсинг | Логи, трассировка и метрики работы агентов и моделей | `observability`, `opentelemetry`, `tracing`, `monitoring`, `llmops` |

## 13. Надёжность, безопасность, контроль

| Слаг | Термин | Определение | Алиасы |
|---|---|---|---|
| `ai-security` | Безопасность AI-систем | Защита от атак на модель и агента, в т.ч. prompt injection | `ai-security`, `llm-security`, `agent-security`, `mcp-security`, `prompt-injection`, `ai-safety` |
| `sandboxing` | Песочница и изоляция исполнения | Запуск недоверенного кода и действий в ограниченной среде | `sandbox`, `isolation`, `containerization` |
| `guardrails` | Ограничения и фильтры вывода | Правила и модерация того, что модель может выдать и сделать | `guardrails`, `moderation`, `content-filtering` |
| `privacy` | Приватность и персональные данные | Защита ПДн, анонимизация, требования 152-ФЗ и GDPR | `privacy`, `pii`, `anonymization`, `data-protection` |
| `access-control` | Доступы и аутентификация | Кто и к чему допущен, роли, ключи, мультиарендность | `auth`, `authentication`, `oauth`, `rbac`, `multi-tenant` |
| `cybersecurity` | Кибербезопасность | Защита инфраструктуры: сканеры, WAF, реагирование | `cybersecurity`, `security-tools`, `malware-scanner`, `waf`, `firewall`, `pentest` |
| `model-eval` | Оценка качества ответов | Измерение качества модели и агента, бенчмарки, регрессии | `evals`, `evaluation`, `benchmark`, `llm-judge` |

---

## Что не входит в словарь

Четыре категории, каждая уходит в свою ось. Без этого они снова заполнят навигацию.

| Категория | Примеры из текущих данных | Куда |
|---|---|---|
| Языки и фреймворки | `typescript` 65, `python` 53, `rust` 50, `golang` 22, `react` 22, `javascript` 13, `nodejs` 12, `fastapi` 11, `java` 9, `swift` 8, `flutter` 6, `vue` 5, `kotlin` 5, `spring-boot` 5 | `repo_language` (уже есть) |
| Платформы и ОС | `macos` 22, `docker` 21, `kubernetes` 16, `linux` 12, `android` 10, `windows` 5, `bsd` 6, `raspberry-pi` 6, `cross-platform` 5 | факт-фильтр, не навигация |
| Продукты и экосистемы | `claude-code` 115, `claude` 87, `codex` 53, `anthropic` 42, `openai` 33, `cursor` 31, `deepseek` 19, `opencode` 18, `gemini` 18, `ollama` 14, `chatgpt` 13, `openrouter` 7, `copilot` 7, `obsidian` 7, `langchain` 9 | **открытый вопрос, см. ниже** |
| Свойства поставки | `self-hosted` 64, `open-source` 39, `local-first` 38, `awesome-list` 18, `awesome` 12 | бейджи/фасеты карточки |

**В blocklist сразу:** `ai` 124, `llm` 140, `generative-ai` 9, `genai` 6, `artificial-intelligence` 6, `machine-learning` 7, `framework` 6, `api` 6, `ui` 5, `hacktoberfest` 6. Причина: в каталоге про AI они не различают ничего — под них подходит каждая запись, значит как навигация они бесполезны.

## Куда уходит текущая масса

| Назначение | Что схлопывается | Примерно привязок |
|---|---|---|
| Раздел **MCP** (`repo_topic`) | `mcp-server` 134, `MCP` 112, `model-context-protocol` 100, `mcp` 14, `mcp-servers` 11, `mcp-client` 9 | ~380 |
| Раздел **AI-агенты** | `ai-agents` 181, `agentic-ai` 46, `agent` 45, `ai-agent` 40, `agents` 36, `autonomous-agents` 12, `llm-agents` 10, `agentic` 10, `llm-agent` 9 | ~390 |
| Раздел **Agent Skills** | `skills` 39, `agent-skills` 31, `claude-skills` 16, `claude-code-plugin` 11 | ~95 |
| Blocklist (слишком общие) | см. выше | ~300 |
| Словарь задач | остальное через алиасы | — |

---

## Открытые вопросы

1. **Продукты и экосистемы — отдельная ось или blocklist?** Масса большая и спрос реальный: «что работает с Claude Code» — осмысленный запрос, `claude-code` стоит на 5-м месте по частоте (115). Как задача это не формулируется. Предлагаю отдельную таксономию-фасет «Совместимость» (`repo_ecosystem`), **не** в навигацию и не в меню — только фильтр в архиве и строка на карточке. Решение нужно до миграции: иначе 400+ привязок уйдут в blocklist безвозвратно.
2. **Пустые утверждённые термины и регулятор.** ~⅓ словаря заводится под будущие статьи и поставщиков, то есть с `count = 0`. Правило «утверждённый с count = 0 шестьдесят дней → в кандидаты» снесёт их в первый же прогон. Нужна оговорка: автопонижение применяется только к терминам, у которых записи **когда-либо были** (флаг «был непустым»); свежезаведённые ждут контента сколько нужно.
3. **Гранулярность групп 7–11.** Бизнес- и бытовые задачи сейчас расписаны ровно: 4–9 терминов на группу. Если под какое-то направление уже есть пул поставщиков — эту группу имеет смысл детализировать сразу, остальные оставить крупными и дробить по мере наполнения.
