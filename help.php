<?php
// help.php
// Страница "Помощь"

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$page_title = 'Помощь';
include __DIR__ . '/includes/header.php';
?>

<div class="help-page">
    <div class="page-header">
        <h1><i class="fas fa-question-circle"></i> Помощь</h1>
        <p>Часто задаваемые вопросы и инструкции</p>
    </div>
    
    <div class="help-grid">
        <div class="help-sidebar">
            <div class="help-nav">
                <a href="#getting-started" class="help-nav-link active" data-section="getting-started">
                    <i class="fas fa-rocket"></i> Начало работы
                </a>
                <a href="#filing" class="help-nav-link" data-section="filing">
                    <i class="fas fa-file-alt"></i> Подача иска
                </a>
                <a href="#case" class="help-nav-link" data-section="case">
                    <i class="fas fa-folder-open"></i> Работа с делом
                </a>
                <a href="#sessions" class="help-nav-link" data-section="sessions">
                    <i class="fas fa-calendar-alt"></i> Судебные заседания
                </a>
                <a href="#appeal" class="help-nav-link" data-section="appeal">
                    <i class="fas fa-gavel"></i> Апелляции
                </a>
                <a href="#faq" class="help-nav-link" data-section="faq">
                    <i class="fas fa-question-circle"></i> Частые вопросы
                </a>
            </div>
        </div>
        
        <div class="help-content">
            <div id="getting-started" class="help-section">
                <h2><i class="fas fa-rocket"></i> Начало работы</h2>
                <div class="help-card">
                    <h3>Регистрация в системе</h3>
                    <p>Для использования системы необходимо зарегистрироваться, заполнив форму на главной странице. Вам потребуется указать:</p>
                    <ul>
                        <li><strong>Логин</strong> — уникальное имя для входа (только латиница, цифры, _ и -)</li>
                        <li><strong>Игровой ник</strong> — ваше имя в игре</li>
                        <li><strong>Email</strong> — для получения уведомлений</li>
                        <li><strong>Пароль</strong> — минимум 6 символов</li>
                    </ul>
                    
                    <h3>Вход в систему</h3>
                    <p>Используйте ваш логин или email и пароль для входа в личный кабинет.</p>
                    
                    <h3>Личный кабинет (дашборд)</h3>
                    <p>После входа вы попадаете на главную страницу, где отображаются:</p>
                    <ul>
                        <li>Ваши дела (в качестве истца и ответчика)</li>
                        <li>Статистика участия</li>
                        <li>Уведомления</li>
                        <li>Быстрые действия</li>
                    </ul>
                </div>
            </div>
            
            <div id="filing" class="help-section" style="display: none;">
                <h2><i class="fas fa-file-alt"></i> Подача иска</h2>
                <div class="help-card">
                    <h3>Как подать иск?</h3>
                    <ol>
                        <li>Нажмите кнопку <strong>"Подать иск"</strong> в дашборде или в верхнем меню</li>
                        <li>Выберите тип дела (уголовное, гражданское, административное и т.д.)</li>
                        <li>Укажите название дела и подробное описание обстоятельств</li>
                        <li>Введите игровой ник ответчика (система проверит, зарегистрирован ли он)</li>
                        <li>При необходимости укажите статью закона и её текст</li>
                        <li>Приложите доказательства (скриншоты, видео, логи)</li>
                        <li>Нажмите <strong>"Подать иск"</strong></li>
                    </ol>
                    
                    <h3>Что происходит после подачи?</h3>
                    <p>Дело получает статус <strong>"Черновик"</strong>. После проверки председателем суда дело будет принято к производству и назначен судья.</p>
                    
                    <div class="help-tip">
                        <i class="fas fa-lightbulb"></i>
                        <div>
                            <strong>Совет:</strong> Чем подробнее вы опишете обстоятельства и приложите больше доказательств, тем быстрее будет рассмотрено ваше дело.
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="case" class="help-section" style="display: none;">
                <h2><i class="fas fa-folder-open"></i> Работа с делом</h2>
                <div class="help-card">
                    <h3>Просмотр дела</h3>
                    <p>На странице дела вы можете увидеть:</p>
                    <ul>
                        <li>Всю информацию о деле (номер, УИД, участники, статус)</li>
                        <li>Описание обстоятельств</li>
                        <li>Прикреплённые доказательства</li>
                        <li>Судейские документы</li>
                        <li>Журнал движения дела</li>
                        <li>Назначенные заседания</li>
                    </ul>
                    
                    <h3>Если вы — судья</h3>
                    <p>Вам доступна панель управления, где вы можете:</p>
                    <ul>
                        <li>Изменить статус дела</li>
                        <li>Назначить заседания</li>
                        <li>Прикрепить документы (текстовые или файловые)</li>
                        <li>Вынести решение/приговор</li>
                    </ul>
                    
                    <h3>Если вы — участник дела</h3>
                    <p>Вы можете отслеживать движение дела, получать уведомления и подавать апелляции.</p>
                </div>
            </div>
            
            <div id="sessions" class="help-section" style="display: none;">
                <h2><i class="fas fa-calendar-alt"></i> Судебные заседания</h2>
                <div class="help-card">
                    <h3>Назначение заседания (для судей)</h3>
                    <ul>
                        <li>В панели управления делом выберите <strong>"Назначить заседание"</strong></li>
                        <li>Укажите дату, время и продолжительность</li>
                        <li>Стороны получат уведомление о назначенном заседании</li>
                    </ul>
                    
                    <h3>Управление заседаниями (для судей)</h3>
                    <p>У каждого заседания есть кнопки управления:</p>
                    <ul>
                        <li><i class="fas fa-tasks"></i> — изменить статус (В процессе, Перенесено, Завершено, Отменено)</li>
                        <li><i class="fas fa-calendar-alt"></i> — перенести на другую дату</li>
                        <li><i class="fas fa-trash-alt"></i> — удалить заседание</li>
                    </ul>
                    
                    <div class="help-tip">
                        <i class="fas fa-lightbulb"></i>
                        <div>
                            <strong>Важно:</strong> При переносе или отмене заседания обязательно укажите причину — стороны получат уведомление.
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="appeal" class="help-section" style="display: none;">
                <h2><i class="fas fa-gavel"></i> Апелляции</h2>
                <div class="help-card">
                    <h3>Когда можно подать апелляцию?</h3>
                    <p>Апелляцию можно подать в течение 30 дней после вынесения решения суда. Право на апелляцию имеют стороны дела (истец и ответчик).</p>
                    
                    <h3>Как подать апелляцию?</h3>
                    <ol>
                        <li>Откройте карточку завершённого дела (статус "Приговор вынесен" или "Решение вынесено")</li>
                        <li>Нажмите кнопку <strong>"Подать апелляцию"</strong></li>
                        <li>Укажите причину обжалования и ваши требования</li>
                        <li>Подтвердите подачу</li>
                    </ol>
                    
                    <h3>Что происходит после подачи?</h3>
                    <p>Создаётся новое апелляционное дело, которое рассматривается председателем суда. Оригинальное дело получает статус "Обжаловано".</p>
                </div>
            </div>
            
            <div id="faq" class="help-section" style="display: none;">
                <h2><i class="fas fa-question-circle"></i> Часто задаваемые вопросы</h2>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>Что такое УИД дела?</span>
                    </div>
                    <div class="faq-answer">
                        <p>УИД (Уникальный идентификатор дела) — это уникальный номер дела в формате RS0052-XX-YYYY-DDMMYY-NN, который позволяет однозначно идентифицировать дело в системе.</p>
                    </div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>Как узнать статус моего дела?</span>
                    </div>
                    <div class="faq-answer">
                        <p>Вы можете отслеживать статус дела в дашборде или на странице поиска дел. Также вы будете получать уведомления при изменении статуса.</p>
                    </div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>Что делать, если я не согласен с решением суда?</span>
                    </div>
                    <div class="faq-answer">
                        <p>Вы можете подать апелляцию в течение 30 дней с момента вынесения решения. Для этого в карточке дела нажмите кнопку "Подать апелляцию".</p>
                    </div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>Как изменить свои данные в профиле?</span>
                    </div>
                    <div class="faq-answer">
                        <p>Перейдите в свой профиль (ссылка в верхнем меню или в дашборде). Там вы можете изменить игровой ник, email, должность, информацию о себе, аватар и контакты.</p>
                    </div>
                </div>
                
                <div class="faq-item">
                    <div class="faq-question">
                        <i class="fas fa-chevron-right"></i>
                        <span>Почему я не вижу админ-панель?</span>
                    </div>
                    <div class="faq-answer">
                        <p>Админ-панель доступна только пользователям с ролью "Председатель суда". Если вы считаете, что это ошибка, обратитесь к администратору.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.help-page {
    max-width: 1200px;
    margin: 0 auto;
}

.help-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.5rem;
}

.help-sidebar {
    position: sticky;
    top: 80px;
    height: fit-content;
}

.help-nav {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.help-nav-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    color: var(--gray-700);
    text-decoration: none;
    transition: all 0.2s;
    border-left: 3px solid transparent;
}

.help-nav-link:hover {
    background: var(--gray-50);
    color: var(--primary);
}

.help-nav-link.active {
    background: var(--gray-50);
    color: var(--primary);
    border-left-color: var(--primary);
}

.help-content {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
}

.help-section h2 {
    font-size: 1.25rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.help-card {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 1.25rem;
    margin-bottom: 1rem;
}

.help-card h3 {
    font-size: 1rem;
    margin-bottom: 0.5rem;
    color: var(--primary);
}

.help-card ul, .help-card ol {
    margin: 0.5rem 0 0.75rem 1.5rem;
}

.help-card li {
    margin: 0.25rem 0;
}

.help-tip {
    background: #e8f0fe;
    border-radius: var(--radius);
    padding: 0.75rem;
    margin-top: 1rem;
    display: flex;
    gap: 0.75rem;
    font-size: 0.875rem;
}

.help-tip i {
    color: var(--primary);
    font-size: 1.25rem;
}

.faq-item {
    border-bottom: 1px solid var(--gray-200);
    margin-bottom: 0.5rem;
}

.faq-question {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 0;
    cursor: pointer;
    font-weight: 500;
}

.faq-question i {
    color: var(--primary);
    transition: transform 0.2s;
}

.faq-question.open i {
    transform: rotate(90deg);
}

.faq-answer {
    display: none;
    padding: 0 0 0.75rem 1.25rem;
    color: var(--gray-600);
    font-size: 0.875rem;
}

.faq-answer.show {
    display: block;
}

@media (max-width: 768px) {
    .help-grid {
        grid-template-columns: 1fr;
    }
    
    .help-sidebar {
        position: static;
    }
    
    .help-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        padding: 0.5rem;
    }
    
    .help-nav-link {
        padding: 0.375rem 0.75rem;
        border-radius: var(--radius);
        border-left: none;
    }
    
    .help-nav-link.active {
        background: var(--primary);
        color: white;
    }
}
</style>

<script>
// Навигация по разделам
document.querySelectorAll('.help-nav-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        
        // Убираем активный класс у всех ссылок
        document.querySelectorAll('.help-nav-link').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
        
        // Показываем выбранный раздел
        const sectionId = this.dataset.section;
        document.querySelectorAll('.help-section').forEach(section => {
            section.style.display = 'none';
        });
        document.getElementById(sectionId).style.display = 'block';
        
        // Прокрутка к началу контента
        document.querySelector('.help-content').scrollTop = 0;
    });
});

// FAQ аккордеон
document.querySelectorAll('.faq-question').forEach(question => {
    question.addEventListener('click', function() {
        this.classList.toggle('open');
        const answer = this.nextElementSibling;
        answer.classList.toggle('show');
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>