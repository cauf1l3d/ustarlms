# Исправленный установщик SCORM —03Nov2026

Application5e61c0d2 прежний, baseline4715383b. Старый a8 завершился отказом до
остановки web и замены файлов; последний owner postcheck ready. Recovery не нужен.
Новый отдельный оператор сохраняет frozen старые инструменты; PHP проверяет только
три PHP-файла, JS/HTML исключены из PHP lint. Повреждённый PHP блокирует применение.

Локальные реальные PHP stdin тесты PASS; CI и точный архив пока ожидаются.
[Квитанция](../context/runtime/studio_scorm_edit_lint_recovery_20261103.json).
Новый журнал: /var/lib/ustar-studio-scorm-edit-lint-5e61c0d2566e/state.json.
Короткий перерыв web; схема, версии, worker и бизнес-данные сохраняются.
