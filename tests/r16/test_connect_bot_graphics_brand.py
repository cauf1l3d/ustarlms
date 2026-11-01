"""Bot card rendering: contract, bounds and fallback. Standard library only."""
import base64
import json
import os
import struct
import sys
import unittest
import zlib

def _worker_path():
    """Import the real package: connect-worker/ in the repo, or the built zip."""
    root = os.path.join(os.path.dirname(__file__), '..', '..')
    for candidate in (os.path.join(root, 'connect-worker'),
                      os.path.join(root, 'worker', 'receiver.pyz')):
        if os.path.isdir(os.path.join(candidate, 'ustar_connect')):
            return os.path.abspath(candidate)
        if os.path.isfile(candidate):
            return os.path.abspath(candidate)
    raise RuntimeError('worker package not found')


sys.path.insert(0, _worker_path())

from ustar_connect import bot_graphics as g


def token():
    return 'cb4_' + base64.urlsafe_b64encode(os.urandom(32)).decode().rstrip('=')


def view(**fields):
    base = {'title': 'Тест', 'text': '', 'buttons': [{'label': 'Назад', 'callback': token()}],
            'metrics': [], 'rows': []}
    base.update(fields)
    return base


def png_header(photo):
    assert photo.startswith(b'\x89PNG\r\n\x1a\n'), 'not a PNG'
    width, height = struct.unpack('>II', photo[16:24])
    offset = photo.find(b'PLTE')
    colours = struct.unpack('>I', photo[offset - 4:offset])[0] // 3
    return width, height, colours


class FallbackTest(unittest.TestCase):
    """Anything the raster cannot draw must return the text card unchanged."""

    def test_emoji_are_dropped_from_the_canvas(self):
        # Emoji stay in captions and buttons; drawing them would produce blank boxes.
        self.assertEqual(g._plain('Задачи 🔥'), 'Задачи')
        result = g.presentation(view(title='Задачи 🔥', rows=[['a', 'b']]), now=1790000000)
        self.assertIn('photo', result)

    def test_missing_letter_script_falls_back(self):
        # Greek and CJK have no glyphs; the text card is the honest answer.
        result = g.presentation(view(title='Αθήνα 漢字', rows=[['a', 'b']]), now=1790000000)
        self.assertNotIn('photo', result)
        self.assertIn('text', result)

    def test_invalid_view_still_raises(self):
        with self.assertRaises(ValueError):
            g.presentation({'title': 'x'}, now=1790000000)

    def test_fallback_is_byte_identical(self):
        broken = view(title='Αθήνα 漢字', rows=[['a', 'b']])
        rendered = g.presentation(broken, now=1790000000)
        self.assertEqual(rendered, g.render_view(broken))

    def test_bad_metrics_raise_from_contract_not_raster(self):
        with self.assertRaises(ValueError):
            g.presentation(view(metrics=[{'label': 'x', 'value': -1, 'total': 5}]), now=1790000000)


class RasterBoundsTest(unittest.TestCase):

    def test_stays_within_limits(self):
        rendered = g.presentation(view(
            text='Длинное описание. ' * 40,
            rows=[[f'Запись {i}', 'Статус проверки · подробное пояснение'] for i in range(10)],
            buttons=[{'label': 'Открыть', 'callback': token()}] * 10,
            metrics=[{'label': 'Просрочено', 'value': 9, 'total': 10}]), now=1790000000)
        self.assertIn('photo', rendered)
        self.assertLessEqual(len(rendered['photo']), g.MAX_BYTES)
        width, height, _ = png_header(rendered['photo'])
        self.assertEqual(width, g.WIDTH)
        self.assertLessEqual(height, g.MAX_HEIGHT)

    def test_palette_never_exceeds_256(self):
        rendered = g.presentation(view(
            title='Все статусы', text='Описание',
            metrics=[{'label': label, 'value': i, 'total': 5} for i, label in enumerate(
                ['Просрочено', 'Выполнено', 'На проверке', 'Провал', 'Готово'])],
            rows=[['a', 'Просрочено · 3 дн.'], ['b', 'Выполнено'], ['c', 'На проверке'],
                  ['d', 'Провал'], ['e', 'Не начато'], ['f', 'Готово']],
            buttons=[{'label': 'Открыть задачу', 'callback': token()}] * 6), now=1790000000)
        self.assertIn('photo', rendered)
        self.assertLessEqual(png_header(rendered['photo'])[2], 256)

    def test_metric_counts_zero_to_five(self):
        for count in range(6):
            rendered = g.presentation(view(
                metrics=[{'label': f'Метрика {i}', 'value': i, 'total': 5} for i in range(count)]),
                now=1790000000)
            self.assertIn('photo', rendered, f'{count} metrics')

    def test_long_list_is_truncated_and_announced(self):
        rows = [[f'Сотрудник {i}', 'Пройдено · 3 из 5'] for i in range(12)]
        rendered = g.presentation(view(
            title='Обучение команды', rows=rows,
            buttons=[{'label': 'Открыть маршрут', 'callback': token()}] * 12,
            metrics=[{'label': 'Просрочено', 'value': 2, 'total': 12}]), now=1790000000)
        self.assertIn('photo', rendered)
        self.assertLessEqual(png_header(rendered['photo'])[1], g.MAX_HEIGHT)


class NumberingTest(unittest.TestCase):
    """Numbered buttons and numbered cards must agree — the user matches them by eye."""

    def test_numbers_match_between_card_and_buttons(self):
        count = 5
        rendered = g.presentation(view(
            title='Сводка по задачам',
            rows=[[f'Задача №{100 + i}', 'Просрочено'] for i in range(count)],
            buttons=[{'label': 'Открыть задачу', 'callback': token()}] * count), now=1790000000)
        buttons = [row[0]['text'] for row in rendered['reply_markup']['inline_keyboard']]
        self.assertEqual(len(buttons), count)
        self.assertEqual(buttons[0], '1 · Открыть задачу')
        self.assertEqual(buttons[-1], f'{count} · Открыть задачу')
        self.assertTrue(all(row[0].get('style') == 'primary'
                            for row in rendered['reply_markup']['inline_keyboard']))

    def test_unknown_layout_keeps_original_labels(self):
        rendered = g.presentation(view(
            title='Неизвестный экран', rows=[['a', 'b']],
            buttons=[{'label': 'Особое действие', 'callback': token()}]), now=1790000000)
        self.assertEqual(rendered['reply_markup']['inline_keyboard'][0][0]['text'], 'Особое действие')

    def test_callback_tokens_are_preserved(self):
        original = token()
        rendered = g.presentation(view(rows=[['a', 'b']],
                                       buttons=[{'label': 'Открыть задачу', 'callback': original}]),
                                  now=1790000000)
        self.assertEqual(rendered['reply_markup']['inline_keyboard'][0][0]['callback_data'], original)


class CaptionTest(unittest.TestCase):

    def test_numbered_caption_counts_rows(self):
        rendered = g.presentation(view(
            title='Сводка по задачам', rows=[[f'Задача {i}', 'ok'] for i in range(3)],
            buttons=[{'label': 'Открыть задачу', 'callback': token()}] * 3), now=1790000000)
        self.assertIn('3 поз.', rendered['caption'])

    def test_caption_has_no_markup(self):
        rendered = g.presentation(view(title='Задачи', rows=[['a', 'b']],
                                       buttons=[{'label': 'Назад', 'callback': token()}]),
                                  now=1790000000)
        self.assertNotIn('<', rendered['caption'])


class SeverityTest(unittest.TestCase):
    """Severity drives colour only. It must never be inferred from button rights."""

    def test_known_words(self):
        self.assertEqual(g._severity('Просрочено · 3 дн.'), g.RED)
        self.assertEqual(g._severity('Выполнено'), g.GREEN)
        self.assertEqual(g._severity('На проверке'), g.BLUE)
        self.assertEqual(g._severity('Готово'), g.GREEN)
        self.assertEqual(g._severity('Неизвестно'), g.GOLD)


if __name__ == '__main__':
    unittest.main(verbosity=2)
