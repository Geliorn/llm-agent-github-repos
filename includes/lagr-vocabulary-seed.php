<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seed data for the task vocabulary (post_tag) — the agreed draft of
 * 2026-10-13 (docs/tag-vocabulary-draft.md): 84 terms, each with a one-line
 * definition (part of the LLM prompt, not documentation) and the aliases that
 * resolve to it without a model call.
 *
 * Read only by LAGR_Vocabulary::seed() and LAGR_Tag_Migrate. Seeding never
 * overwrites what an editor changed afterwards — see seed().
 *
 * Term row: array( slug, name, definition, aliases ).
 */
return array(

	'groups'   => array(
		array(
			'group' => 'Разработка и код',
			'terms' => array(
				array( 'code-generation', 'Генерация кода', 'Создание нового кода по описанию задачи', array( 'code-generation', 'ai-coding', 'agentic-coding', 'vibe-coding', 'code-completion' ) ),
				array( 'code-review', 'Ревью и аудит кода', 'Проверка готового кода на ошибки, стиль и уязвимости', array( 'code-review', 'static-analysis', 'pr-review', 'linting' ) ),
				array( 'testing-qa', 'Тестирование и QA', 'Написание и прогон тестов, поиск регрессий', array( 'testing', 'qa', 'e2e', 'test-automation' ) ),
				array( 'debugging', 'Отладка и диагностика', 'Поиск причины сбоя в работающей системе', array( 'debugging', 'troubleshooting', 'error-analysis' ) ),
				array( 'dev-tooling', 'Инструменты разработчика', 'CLI, терминал, IDE, SDK, обвязка рабочего места', array( 'developer-tools', 'devtools', 'cli', 'terminal', 'tui', 'ide', 'sdk', 'harness', 'agent-harness' ) ),
				array( 'code-docs', 'Документирование кода', 'Генерация и поддержка технической документации по коду', array( 'documentation', 'docstring', 'api-docs' ) ),
				array( 'legacy-migration', 'Рефакторинг и миграция legacy', 'Перенос и переписывание старого кода на новый стек', array( 'refactoring', 'migration', 'modernization' ) ),
			),
		),
		array(
			'group' => 'Агенты и автоматизация процессов',
			'terms' => array(
				array( 'agent-orchestration', 'Оркестрация агентов', 'Координация нескольких агентов или шагов в одной задаче', array( 'agent-orchestration', 'multi-agent', 'orchestration', 'agent-framework', 'agentic-workflow', 'agentic-workflows', 'a2a', 'acp', 'langgraph' ) ),
				array( 'agent-memory', 'Память агента', 'Хранение и переиспользование контекста между сессиями', array( 'agent-memory', 'ai-memory', 'memory', 'long-term-memory', 'llm-memory', 'persistent-memory' ) ),
				array( 'tool-use', 'Подключение инструментов и API', 'Доступ модели к внешним функциям, сервисам и данным', array( 'llm-tools', 'mcp-tools', 'function-calling', 'tool-calling', 'openapi' ) ),
				array( 'workflow-automation', 'Автоматизация рабочих процессов', 'Сквозные цепочки действий вместо ручных операций', array( 'workflow-automation', 'workflow', 'automation', 'low-code', 'no-code' ) ),
				array( 'browser-automation', 'Автоматизация браузера и веб-действий', 'Действия на сайтах от имени пользователя, сбор данных с веба', array( 'browser-automation', 'web-scraping', 'computer-use', 'rpa' ) ),
				array( 'scheduled-agents', 'Агенты по расписанию', 'Регулярный автозапуск без участия человека', array( 'cron', 'scheduler', 'background-agent' ) ),
				array( 'human-in-loop', 'Контроль человеком', 'Согласование и подтверждение шагов перед выполнением', array( 'human-in-the-loop', 'approval-workflow' ) ),
			),
		),
		array(
			'group' => 'Знания, поиск, документы',
			'terms' => array(
				array( 'rag-search', 'Поиск по своим документам (RAG)', 'Ответы по собственной базе документов, а не по общим знаниям модели', array( 'rag', 'agentic-rag', 'semantic-search', 'vector-search', 'embeddings' ) ),
				array( 'knowledge-base', 'База знаний и вики', 'Ведение и структурирование корпоративных или личных знаний', array( 'knowledge-base', 'knowledge-management', 'wiki', 'second-brain', 'pkm', 'knowledge-graph', 'notes' ) ),
				array( 'document-processing', 'Обработка документов', 'Распознавание, разбор и извлечение полей из файлов и сканов', array( 'document-processing', 'ocr', 'pdf', 'document-parsing' ) ),
				array( 'summarization', 'Пересказ и выжимки', 'Сжатие длинного текста, встречи или переписки до главного', array( 'summarization', 'tldr' ) ),
				array( 'translation', 'Перевод', 'Перевод текста и документов между языками', array( 'translation', 'localization', 'i18n' ) ),
				array( 'transcription', 'Расшифровка аудио и видео', 'Перевод речи в текст с разметкой по говорящим', array( 'transcription', 'speech-to-text', 'stt', 'subtitles' ) ),
				array( 'prompting', 'Подготовка контекста и промптов', 'Проектирование запросов и сжатие контекста под задачу', array( 'prompt-engineering', 'context-engineering', 'prompt-templates' ) ),
			),
		),
		array(
			'group' => 'Данные и аналитика',
			'terms' => array(
				array( 'data-extraction', 'Извлечение и парсинг данных', 'Получение структурированных данных из неструктурированных источников', array( 'data-extraction', 'parsing', 'structured-output' ) ),
				array( 'data-prep', 'Подготовка и очистка данных', 'Нормализация, дедупликация, приведение к единому виду', array( 'data-cleaning', 'etl', 'data-quality' ) ),
				array( 'bi-reporting', 'Отчёты и дашборды', 'Регулярная отчётность и визуализация показателей', array( 'dashboard', 'bi', 'reporting', 'data-visualization' ) ),
				array( 'db-access', 'Работа с базами данных', 'Запросы к БД на естественном языке, доступ агента к данным', array( 'database', 'sql', 'text-to-sql' ) ),
				array( 'forecasting', 'Прогнозирование и планирование', 'Оценка будущих значений: спрос, загрузка, выручка', array( 'forecasting', 'prediction', 'planning' ) ),
				array( 'anomaly-detection', 'Поиск аномалий', 'Обнаружение отклонений в данных, метриках и поведении', array( 'anomaly-detection', 'outlier-detection', 'fraud-detection' ) ),
			),
		),
		array(
			'group' => 'Контент и творчество',
			'terms' => array(
				array( 'text-generation', 'Генерация текста', 'Создание текстов: посты, письма, описания, статьи', array( 'text-generation', 'copywriting', 'content-generation' ) ),
				array( 'copy-editing', 'Редактура и проверка текста', 'Правка, стиль, грамотность, приведение к гайдлайну', array( 'proofreading', 'editing', 'grammar' ) ),
				array( 'image-generation', 'Генерация и обработка изображений', 'Создание картинок по описанию, ретушь, удаление фона', array( 'image-generation', 'text-to-image', 'image-editing', 'inpainting' ) ),
				array( 'video-generation', 'Генерация и монтаж видео', 'Создание видео, нарезка, субтитры, озвучка', array( 'video-generation', 'text-to-video', 'video-editing' ) ),
				array( 'audio-music', 'Звук, голос и музыка', 'Синтез речи, озвучка, генерация и обработка музыки', array( 'tts', 'text-to-speech', 'voice-cloning', 'music-generation', 'audio-generation', 'vgm' ) ),
				array( 'design-assets', 'Дизайн и макеты', 'Логотипы, баннеры, UI-макеты, фирменный стиль', array( 'design', 'ui-design', 'logo', 'figma' ) ),
				array( 'presentations', 'Презентации и документы', 'Слайды, коммерческие предложения, отчётные документы', array( 'presentations', 'slides', 'pitch-deck' ) ),
				array( 'seo-content', 'SEO и контент для сайтов', 'Семантика, тексты под поиск, оптимизация страниц', array( 'seo', 'content-marketing' ) ),
			),
		),
		array(
			'group' => 'Коммуникации и поддержка клиентов',
			'terms' => array(
				array( 'chat-assistants', 'Чат-боты и ассистенты', 'Диалоговый интерфейс к знаниям или сервису', array( 'chatbot', 'chatbots', 'ai-assistant', 'personal-assistant', 'conversational-ai' ) ),
				array( 'customer-support', 'Поддержка клиентов', 'Обработка обращений, тикетов, первая линия', array( 'customer-support', 'helpdesk', 'ticketing' ) ),
				array( 'voice-agents', 'Голосовые агенты и телефония', 'Приём и совершение звонков голосом', array( 'voice-agent', 'voice-ai', 'telephony', 'ivr' ) ),
				array( 'messaging', 'Мессенджеры и соцсети', 'Работа через Telegram, WhatsApp, VK, Slack и соцсети', array( 'telegram-bot', 'whatsapp', 'slack', 'discord', 'smm' ) ),
				array( 'email-assist', 'Работа с почтой', 'Разбор входящих, черновики ответов, рассылки', array( 'email', 'inbox', 'newsletter' ) ),
				array( 'meeting-assist', 'Встречи и протоколы', 'Запись, расшифровка и итоги созвонов с задачами', array( 'meeting-notes', 'minutes' ) ),
			),
		),
		array(
			'group' => 'Продажи и маркетинг',
			'terms' => array(
				array( 'lead-generation', 'Лидогенерация', 'Поиск и квалификация потенциальных клиентов', array( 'lead-generation', 'prospecting', 'outreach' ) ),
				array( 'crm-automation', 'CRM и воронка продаж', 'Ведение сделок, статусы, follow-up, напоминания', array( 'crm', 'sales-automation', 'pipeline' ) ),
				array( 'ads-automation', 'Реклама и кампании', 'Создание, запуск и оптимизация рекламы', array( 'advertising', 'ppc', 'ad-automation' ) ),
				array( 'market-research', 'Исследование рынка и конкурентов', 'Сбор и анализ данных о рынке, ценах, конкурентах', array( 'market-research', 'competitor-analysis' ) ),
				array( 'pricing-promo', 'Ценообразование и промо', 'Расчёт цен, скидок, акций', array( 'pricing', 'dynamic-pricing' ) ),
				array( 'ecommerce', 'Маркетплейсы и интернет-магазины', 'Карточки товаров, выгрузки, работа на площадках', array( 'ecommerce', 'marketplace', 'woocommerce', 'product-catalog' ) ),
			),
		),
		array(
			'group' => 'Финансы, учёт, юридическое',
			'terms' => array(
				array( 'accounting', 'Бухгалтерия и первичка', 'Проводки, акты, накладные, сверки', array( 'accounting', 'bookkeeping' ) ),
				array( 'invoicing-payments', 'Счета и платежи', 'Выставление счетов, приём и сверка платежей', array( 'invoicing', 'payments', 'billing' ) ),
				array( 'financial-analysis', 'Финансовый анализ и бюджеты', 'Юнит-экономика, бюджетирование, управленческий учёт', array( 'financial-analysis', 'budgeting', 'fpa' ) ),
				array( 'legal-docs', 'Договоры и юридические документы', 'Подготовка и проверка договоров, правовой анализ', array( 'legal', 'contract-analysis', 'legaltech' ) ),
				array( 'compliance', 'Комплаенс и регуляторика', 'Соответствие требованиям, аудит решений, журнал действий', array( 'compliance', 'ai-governance', 'audit-trail', 'governance' ) ),
				array( 'tax-reporting', 'Налоги и отчётность', 'Расчёт налогов, подготовка и сдача отчётности', array( 'tax', 'tax-reporting' ) ),
			),
		),
		array(
			'group' => 'Персонал и обучение',
			'terms' => array(
				array( 'recruiting', 'Подбор персонала', 'Скрининг резюме, интервью, оценка кандидатов', array( 'recruiting', 'hiring', 'ats', 'resume-screening' ) ),
				array( 'hr-processes', 'HR-процессы и адаптация', 'Онбординг, кадровые документы, внутренние запросы', array( 'hr', 'onboarding', 'people-ops' ) ),
				array( 'corporate-training', 'Корпоративное обучение', 'Курсы, тренажёры и проверка знаний для сотрудников', array( 'training', 'lms', 'e-learning' ) ),
				array( 'tutoring', 'Репетитор и помощь в учёбе', 'Объяснение материала, разбор задач, подготовка к экзаменам', array( 'tutoring', 'education', 'study-assistant', 'homework' ) ),
			),
		),
		array(
			'group' => 'Операции и логистика',
			'terms' => array(
				array( 'project-management', 'Управление проектами и задачами', 'Планирование, трекинг, распределение работ', array( 'project-management', 'task-management', 'productivity', 'workspace', 'kanban' ) ),
				array( 'logistics', 'Логистика и склад', 'Маршруты, доставка, учёт запасов', array( 'logistics', 'supply-chain', 'inventory', 'warehouse' ) ),
				array( 'procurement', 'Закупки и поставщики', 'Выбор поставщика, тендеры, сравнение предложений', array( 'procurement', 'sourcing', 'rfp' ) ),
				array( 'scheduling', 'Расписание и запись клиентов', 'Календарь, слоты, бронирование, смены', array( 'scheduling', 'booking', 'calendar', 'shift-planning' ) ),
				array( 'field-service', 'Выездные работы и сервис', 'Заявки, наряды, выездные специалисты', array( 'field-service', 'work-orders', 'dispatch' ) ),
			),
		),
		array(
			'group' => 'Личные и бытовые задачи',
			'terms' => array(
				array( 'personal-finance', 'Личные финансы', 'Бюджет, траты, накопления, инвест-учёт', array( 'personal-finance', 'budget-tracker', 'expense-tracking' ) ),
				array( 'health-fitness', 'Здоровье и спорт', 'Тренировки, питание, самочувствие, напоминания', array( 'health', 'fitness', 'nutrition', 'wellness' ) ),
				array( 'cooking', 'Еда и рецепты', 'Рецепты, меню, список покупок', array( 'cooking', 'recipes', 'meal-planning' ) ),
				array( 'travel', 'Путешествия и поездки', 'Маршруты, билеты, жильё, планирование поездки', array( 'travel', 'trip-planning', 'itinerary' ) ),
				array( 'smart-home', 'Умный дом', 'Управление устройствами и сценариями в доме', array( 'smart-home', 'home-automation', 'home-assistant', 'iot' ) ),
				array( 'photo-archive', 'Фото- и видеоархив', 'Разбор, поиск и упорядочивание личных снимков', array( 'photo-management', 'photo-organization', 'media-library' ) ),
				array( 'personal-docs', 'Личные документы и госуслуги', 'Заявления, справки, формы, общение с ведомствами', array( 'paperwork', 'forms', 'govtech' ) ),
				array( 'shopping', 'Покупки и выбор товаров', 'Сравнение, подбор, отслеживание цен', array( 'shopping', 'price-tracking', 'product-comparison' ) ),
				array( 'games', 'Игры и развлечения', 'Игры, эмуляция, развлекательные приложения', array( 'game-development', 'gaming', 'emulation', 'emulator', 'retroarch', 'libretro' ) ),
			),
		),
		array(
			'group' => 'Инфраструктура и запуск моделей',
			'terms' => array(
				array( 'local-models', 'Локальный запуск моделей', 'Запуск модели на своём железе, без внешнего API', array( 'local-llm', 'local-ai', 'on-premise', 'offline-ai' ) ),
				array( 'model-gateway', 'Шлюз и маршрутизация моделей', 'Единая точка доступа к нескольким моделям и провайдерам', array( 'ai-gateway', 'llm-gateway', 'api-gateway', 'mcp-gateway', 'proxy', 'routing' ) ),
				array( 'fine-tuning', 'Дообучение и адаптация моделей', 'Настройка модели под свои данные и задачу', array( 'fine-tuning', 'lora', 'training-pipeline' ) ),
				array( 'deployment', 'Развёртывание и эксплуатация', 'Поставка, запуск и сопровождение решения в проде', array( 'deployment', 'devops', 'cloud-native', 'self-hosting' ) ),
				array( 'cost-optimization', 'Оптимизация затрат на модели', 'Снижение расхода токенов и стоимости запросов', array( 'cost-optimization', 'token-optimization', 'caching' ) ),
				array( 'observability', 'Наблюдаемость и трейсинг', 'Логи, трассировка и метрики работы агентов и моделей', array( 'observability', 'opentelemetry', 'tracing', 'monitoring', 'llmops' ) ),
			),
		),
		array(
			'group' => 'Надёжность, безопасность, контроль',
			'terms' => array(
				array( 'ai-security', 'Безопасность AI-систем', 'Защита от атак на модель и агента, в т.ч. prompt injection', array( 'ai-security', 'llm-security', 'agent-security', 'mcp-security', 'prompt-injection', 'ai-safety' ) ),
				array( 'sandboxing', 'Песочница и изоляция исполнения', 'Запуск недоверенного кода и действий в ограниченной среде', array( 'sandbox', 'isolation', 'containerization' ) ),
				array( 'guardrails', 'Ограничения и фильтры вывода', 'Правила и модерация того, что модель может выдать и сделать', array( 'guardrails', 'moderation', 'content-filtering' ) ),
				array( 'privacy', 'Приватность и персональные данные', 'Защита ПДн, анонимизация, требования 152-ФЗ и GDPR', array( 'privacy', 'pii', 'anonymization', 'data-protection' ) ),
				array( 'access-control', 'Доступы и аутентификация', 'Кто и к чему допущен, роли, ключи, мультиарендность', array( 'auth', 'authentication', 'oauth', 'rbac', 'multi-tenant' ) ),
				array( 'cybersecurity', 'Кибербезопасность', 'Защита инфраструктуры: сканеры, WAF, реагирование', array( 'cybersecurity', 'security-tools', 'malware-scanner', 'waf', 'firewall', 'pentest' ) ),
				array( 'model-eval', 'Оценка качества ответов', 'Измерение качества модели и агента, бенчмарки, регрессии', array( 'evals', 'evaluation', 'benchmark', 'llm-judge' ) ),
			),
		),
	),

	// Section axis (repo_topic). These strings never become tasks: one term —
	// one axis, otherwise /repo-topic/mcp/ and /tag/mcp/ are two URLs with the
	// same content. Keys are repo_topic slugs (the rule topics).
	'sections' => array(
		'mcp'          => array( 'mcp', 'mcp-server', 'mcp-servers', 'mcp-client', 'model-context-protocol' ),
		'ai-agent'     => array( 'ai-agent', 'ai-agents', 'agentic-ai', 'agent', 'agents', 'autonomous-agent', 'autonomous-agents', 'llm-agent', 'llm-agents', 'agentic' ),
		'agent-skills' => array( 'agent-skills', 'skills', 'claude-skills', 'claude-code-plugin' ),
	),

	// Too general for an AI catalog — every record fits, so they tell nothing.
	'blocklist' => array( 'ai', 'llm', 'generative-ai', 'genai', 'artificial-intelligence', 'machine-learning', 'framework', 'api', 'ui', 'hacktoberfest' ),

	// Languages, frameworks, platforms: facts, not tasks (repo_language has
	// them). Removed from post_tag on migration and blocklisted.
	'tech'      => array(
		'typescript', 'javascript', 'python', 'rust', 'golang', 'go', 'java', 'kotlin', 'swift', 'php', 'ruby', 'csharp', 'cpp', 'c', 'dart', 'lua', 'zig', 'elixir', 'scala', 'haskell', 'shell', 'bash', 'html', 'css',
		'react', 'vue', 'svelte', 'nextjs', 'nodejs', 'node', 'fastapi', 'django', 'flask', 'spring-boot', 'flutter', 'tailwindcss',
		'macos', 'linux', 'windows', 'android', 'ios', 'bsd', 'raspberry-pi', 'cross-platform', 'docker', 'kubernetes',
	),

	// Open question 12.1, decided 2026-10-14: products/ecosystems stay as
	// candidates (invisible, records kept) until a facet is decided. Listed
	// only so the migration report can count them separately.
	'ecosystem' => array( 'claude-code', 'claude', 'codex', 'anthropic', 'openai', 'cursor', 'deepseek', 'opencode', 'gemini', 'ollama', 'chatgpt', 'openrouter', 'copilot', 'obsidian', 'langchain' ),
);
