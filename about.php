<?php
// about.php
// Страница "О системе"

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$page_title = 'О системе';
include __DIR__ . '/includes/header.php';
?>

<div class="info-page">
    <div class="page-header">
        <h1><i class="fas fa-info-circle"></i> О системе</h1>
        <p>Государственная автоматизированная система «Юстиция»</p>
    </div>
    
    <div class="info-grid">
        <div class="info-card-full">
            <h2>Что такое ГАС «Юстиция»?</h2>
            <p>Государственная автоматизированная система «Юстиция» — это официальная судебная система Motion Project, созданная для автоматизации судебного делопроизводства и обеспечения открытости правосудия.</p>
            <p>Система позволяет подавать исковые заявления, отслеживать движение дел, участвовать в судебных заседаниях, обжаловать решения и знакомиться с судебной практикой.</p>
        </div>
        
        <div class="info-card-full">
            <h2><i class="fas fa-gavel"></i> Возможности системы</h2>
            <div class="features-grid">
                <div class="feature-item">
                    <i class="fas fa-file-alt"></i>
                    <h3>Подача исков</h3>
                    <p>Онлайн-подача исковых заявлений с приложением доказательств</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-history"></i>
                    <h3>Движение дела</h3>
                    <p>Полная хронология всех процессуальных действий</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-calendar-alt"></i>
                    <h3>Судебные заседания</h3>
                    <p>Назначение, перенос и отмена заседаний</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-gavel"></i>
                    <h3>Апелляции</h3>
                    <p>Обжалование судебных решений в вышестоящей инстанции</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-file-pdf"></i>
                    <h3>Судейские документы</h3>
                    <p>Прикрепление текстовых и файловых документов</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-bell"></i>
                    <h3>Уведомления</h3>
                    <p>Мгновенное оповещение о всех изменениях по делу</p>
                </div>
            </div>
        </div>
        
        <div class="info-card-full">
            <h2><i class="fas fa-users"></i> Роли участников</h2>
            <div class="roles-grid">
                <div class="role-item citizen">
                    <i class="fas fa-user"></i>
                    <div class="role-info">
                        <h3>Гражданин</h3>
                        <p>Подача исков, отслеживание дел, апелляции</p>
                    </div>
                </div>
                <div class="role-item lawyer">
                    <i class="fas fa-user-tie"></i>
                    <div class="role-info">
                        <h3>Адвокат</h3>
                        <p>Представление интересов доверителей</p>
                    </div>
                </div>
                <div class="role-item prosecutor">
                    <i class="fas fa-balance-scale"></i>
                    <div class="role-info">
                        <h3>Прокурор</h3>
                        <p>Поддержание государственного обвинения</p>
                    </div>
                </div>
                <div class="role-item judge">
                    <i class="fas fa-gavel"></i>
                    <div class="role-info">
                        <h3>Судья</h3>
                        <p>Рассмотрение дел, вынесение решений</p>
                    </div>
                </div>
                <div class="role-item chairman">
                    <i class="fas fa-crown"></i>
                    <div class="role-info">
                        <h3>Председатель суда</h3>
                        <p>Управление системой, назначение судей</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="info-card-full">
            <h2><i class="fas fa-info-circle"></i> Версия системы</h2>
            <p><strong>ГАС «Юстиция» v1.0</strong></p>
            <p>Дата релиза: 2026 год</p>
            <p>Разработчик: Dendi</p>
        </div>
    </div>
</div>

<style>
.info-page {
    max-width: 1200px;
    margin: 0 auto;
}

.info-grid {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.info-card-full {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
}

.info-card-full h2 {
    font-size: 1.25rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-card-full p {
    line-height: 1.6;
    margin-bottom: 0.75rem;
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.feature-item {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 1rem;
    text-align: center;
}

.feature-item i {
    font-size: 2rem;
    color: var(--primary);
    margin-bottom: 0.5rem;
}

.feature-item h3 {
    font-size: 1rem;
    margin-bottom: 0.25rem;
}

.feature-item p {
    font-size: 0.8rem;
    color: var(--gray-600);
}

.roles-grid {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.role-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem;
    border-radius: var(--radius);
}

.role-item i {
    font-size: 1.5rem;
    width: 40px;
    text-align: center;
}

.role-info h3 {
    font-size: 1rem;
    margin-bottom: 0.125rem;
}

.role-info p {
    font-size: 0.75rem;
    margin: 0;
}

.role-item.citizen { background: #e8f0fe; }
.role-item.lawyer { background: #e8f5e9; }
.role-item.prosecutor { background: #fef3e8; }
.role-item.judge { background: #fce4ec; }
.role-item.chairman { background: linear-gradient(135deg, #c4a747, #8b4513); color: white; }
.role-item.chairman .role-info p { color: #f0e6d2; }

@media (max-width: 768px) {
    .features-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>