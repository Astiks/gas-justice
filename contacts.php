<?php
// contacts.php
// Страница "Контакты"

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$page_title = 'Контакты';
include __DIR__ . '/includes/header.php';
?>

<div class="contacts-page">
    <div class="page-header">
        <h1><i class="fas fa-envelope"></i> Контакты</h1>
        <p>Связь с администрацией системы</p>
    </div>
    
    <div class="contacts-grid">
        <div class="contacts-card">
            <h2><i class="fas fa-gavel"></i> Официальный аккаунт суда</h2>
            <p>По всем вопросам обращайтесь на форум:</p>
            <a href="https://forum.mn-p.com/members/mezhrajonnyj-sud.5100/" target="_blank" class="contact-link forum">
                <i class="fas fa-gavel"></i> Межрайонный суд на форуме
            </a>
        </div>
        
        <div class="contacts-card">
            <h2><i class="fab fa-discord"></i> Discord</h2>
            <p>Присоединяйтесь к нашему Discord-серверу:</p>
            <a href="https://discord.gg/motionproject" target="_blank" class="contact-link discord">
                <i class="fab fa-discord"></i> Motion Project Discord
            </a>
        </div>
        
        <div class="contacts-card">
            <h2><i class="fab fa-vk"></i> ВКонтакте</h2>
            <p>Официальное сообщество проекта:</p>
            <a href="https://vk.com/gta_crrmp" target="_blank" class="contact-link vk">
                <i class="fab fa-vk"></i> vk.com/gta_crrmp
            </a>
        </div>
        
        <div class="contacts-card">
            <h2><i class="fas fa-globe"></i> Сайт проекта</h2>
            <a href="https://motion-ums.ru" target="_blank" class="contact-link site">
                <i class="fas fa-globe"></i> motion-ums.ru
            </a>
        </div>
    </div>
    
    <div class="contacts-card full-width">
        <h2><i class="fas fa-info-circle"></i> Как связаться с судьёй?</h2>
        <p>Для связи с конкретным судьёй вы можете использовать:</p>
        <ul>
            <li><strong>Через карточку дела</strong> — в разделе "Информация о деле" есть ссылка на профиль судьи, где указаны контакты</li>
            <li><strong>Через Discord сервер</strong> — найдите судью в общем чате или напишите в личные сообщения</li>
            <li><strong>По email</strong> — напишите на общий адрес с указанием номера дела и ФИО судьи</li>
        </ul>
    </div>
    
    <div class="contacts-card full-width">
        <h2><i class="fas fa-clock"></i> Часы работы</h2>
        <p>Судебная система работает круглосуточно, однако рассмотрение дел происходит в рабочее время судей.</p>
        <ul>
            <li><strong>Подача исков и документов:</strong> 24/7</li>
            <li><strong>Судебные заседания:</strong> по расписанию, указанному в карточке дела</li>
            <li><strong>Техническая поддержка:</strong> по возможности администратора</li>
        </ul>
    </div>
</div>

<style>
.contacts-page {
    max-width: 1200px;
    margin: 0 auto;
}

.contacts-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.contacts-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    flex: 1;
    min-width: 250px;
}

.contacts-card.full-width {
    flex: 1 1 100%;
    margin-bottom: 1.5rem;
}

.contacts-card.full-width:last-child {
    margin-bottom: 0;
}

.contacts-card h2 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.contacts-card p {
    margin-bottom: 1rem;
    color: var(--gray-600);
}

.contacts-card ul {
    margin: 0.5rem 0 0 1.5rem;
}

.contacts-card li {
    margin: 0.25rem 0;
}

.contact-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: var(--gray-50);
    border-radius: var(--radius);
    color: var(--primary);
    text-decoration: none;
    transition: all 0.2s;
}

.contact-link:hover {
    background: var(--gray-100);
    transform: translateY(-2px);
}

.contact-link.discord {
    background: #5865F2;
    color: white;
}

.contact-link.discord:hover {
    background: #4752c4;
}

.contact-link.vk {
    background: #2787F5;
    color: white;
}

.contact-link.vk:hover {
    background: #1e6bc4;
}

.contact-link.forum {
    background: #2c3e50;
    color: white;
}

.contact-link.forum:hover {
    background: #1a2a3a;
}

@media (max-width: 768px) {
    .contacts-grid {
        flex-direction: column;
    }
    
    .contacts-card {
        min-width: auto;
    }
}

.contact-link.site {
    background: var(--primary);
    color: white;
}

.contact-link.site:hover {
    background: var(--primary-dark);
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>