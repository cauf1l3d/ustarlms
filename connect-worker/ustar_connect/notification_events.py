"""Bounded public event names; private Academy titles and bodies never cross this boundary."""
EVENTS = {
    'registration_requested': ('registration', 'Новая заявка на регистрацию'),
    'registration_approved': ('registration', 'Регистрация одобрена'),
    'registration_rejected': ('registration', 'Решение по регистрации'),
    'registration_adaptation_ready': ('registration', 'Адаптация сотрудника готова'),
    'task_assigned': ('tasks', 'Назначена задача'),
    'task_revised': ('tasks', 'Задача изменена'),
    'task_reassigned': ('tasks', 'Исполнитель задачи изменён'),
    'task_started': ('tasks', 'Работа над задачей начата'),
    'task_sent_for_review': ('tasks', 'Задача отправлена на проверку'),
    'task_completed': ('tasks', 'Задача выполнена'),
    'task_approved': ('tasks', 'Результат задачи принят'),
    'task_returned': ('tasks', 'Задача возвращена на доработку'),
    'task_cancelled': ('tasks', 'Задача отменена'),
    'task_reminder': ('tasks', 'Напоминание о задаче'),
    'task_escalation': ('tasks', 'Требуется внимание к задаче'),
    'feed_comment': ('feed', 'Новый комментарий'),
    'feed_reply': ('feed', 'Новый ответ на комментарий'),
    'grade_request': ('grades', 'Новая заявка на грейд'),
    'grade_approved': ('grades', 'Грейд подтверждён'),
    'grade_returned': ('grades', 'Заявка на грейд возвращена'),
}


def event_label(category, event):
    if not isinstance(category, str) or not isinstance(event, str):
        raise ValueError('invalid_notification_event')
    item = EVENTS.get(event)
    if item is None or item[0] != category:
        raise ValueError('invalid_notification_event')
    return item[1]
