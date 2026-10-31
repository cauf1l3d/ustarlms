"""Real raster, readable choices, deterministic transport and uncertainty boundaries."""
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'connect-worker'))


def view():
    return {'title':'📊 Сводка по задачам','text':'Выберите задачу.',
        'metrics':[{'label':'Активные','value':12,'total':18},
                   {'label':'На проверке','value':3,'total':18},
                   {'label':'Просрочены','value':12,'total':18}],
        'rows':[['Калина Казанбиева','назначена'],['Ислам Силахудинов','на проверке']],
        'buttons':[{'label':'Презентация платформы и старт обучения','callback':'cb4_'+'A'*43},
                   {'label':'Подтвердить вход скрином главной страницы','callback':'cb4_'+'B'*42+'A'},
                   {'label':'Далее →','callback':'cb4_'+'C'*42+'A'},
                   {'label':'⌂ Главное меню','callback':'cb4_'+'D'*42+'A'}]}


class Graphics(unittest.TestCase):
    def test_card_is_a_real_bounded_png_with_numbered_short_choices(self):
        from ustar_connect.bot_graphics import presentation
        original=view();message=presentation(original,now=1793365200)
        import struct,zlib
        width,height=struct.unpack('>II',message['photo'][16:24])
        self.assertEqual(960,width);self.assertLessEqual(height,2400)
        self.assertLess(len(message['photo']),262144)
        self.assertGreater(len(message['photo']),1000)
        buttons=message['reply_markup']['inline_keyboard']
        self.assertEqual('1 · Открыть задачу',buttons[0][0]['text'])
        self.assertEqual('2 · Открыть задачу',buttons[1][0]['text'])
        self.assertEqual(original['buttons'][0]['callback'],buttons[0][0]['callback_data'])
        self.assertNotIn(b'cb4_',message['photo'])
        self.assertEqual('Презентация платформы и старт обучения',original['buttons'][0]['label'])

    def test_styles_are_semantic_and_unknown_buttons_remain_primary(self):
        from ustar_connect.bot_cards import render_view
        data=view();data['buttons']=[{'label':label,'callback':'cb4_'+'A'*43} for label in
            ('Одобрить','✓ Принять результат','Отклонить','↩ Вернуть с комментарием','Мои задачи','⌂ Главное меню')]
        buttons=render_view(data)['reply_markup']['inline_keyboard']
        self.assertEqual(['success','success','danger','danger','primary',None],
            [row[0].get('style') for row in buttons])

    def test_long_cards_retain_full_text_and_original_button_labels(self):
        from ustar_connect.bot_graphics import presentation
        data=view();data['title']='Карточка задачи';data['text']='Длинный ответ сотрудника. '*60
        data['rows']=[['Длинное название '*10,'Подробное состояние '*10] for _ in range(12)]
        result=presentation(data)
        self.assertNotIn('photo',result)
        self.assertIn('Длинный ответ сотрудника.',result['text'])
        self.assertEqual(data['buttons'][0]['label'],result['reply_markup']['inline_keyboard'][0][0]['text'])

    def test_invalid_view_is_rejected_before_drawing(self):
        from ustar_connect.bot_graphics import presentation
        data=view();data['metrics'][0]['value']=-1
        with self.assertRaises(ValueError):presentation(data)

    def test_empty_and_zero_total_cards_render_without_fake_progress(self):
        from ustar_connect.bot_graphics import presentation
        for rows,metrics in [([],[]),([],[{'label':'Маршрут','value':0,'total':0}])]:
            data=view();data.update(rows=rows,metrics=metrics,buttons=[])
            self.assertTrue(presentation(data)['photo'].startswith(b'\x89PNG\r\n\x1a\n'))


if __name__=='__main__':unittest.main()

class MediaTransport(unittest.TestCase):
    def api(self):
        from ustar_connect.telegram_api import TelegramClient
        api=TelegramClient('123456:'+'x'*30)
        api._opener=Mock()
        class Response(io.BytesIO):
            def __enter__(self):return self
            def __exit__(self,*_):self.close()
        api._opener.open.side_effect=lambda *a,**k:Response(b'{"ok":true,"result":{"message_id":8,"chat":{"id":55,"type":"private"}}}')
        return api

    def test_photo_upload_and_text_to_media_edit_use_multipart_not_paths_or_urls(self):
        from ustar_connect.bot_graphics import presentation
        api=self.api();payload=presentation(view())
        api.send_bot_view(55,payload)
        request=api._opener.open.call_args.args[0]
        self.assertTrue(request.full_url.endswith('/sendPhoto'))
        self.assertIn('multipart/form-data',request.get_header('Content-type'))
        self.assertIn(b'filename="ustar-card.png"',request.data)
        self.assertIn(b'\x89PNG\r\n\x1a\n',request.data)
        self.assertIn(b'"style":"primary"',request.data)
        self.assertNotIn(b'_fallback',request.data)
        self.assertIn(b'protect_content',request.data)
        api.edit_bot_view(55,7,payload)
        request=api._opener.open.call_args.args[0]
        self.assertTrue(request.full_url.endswith('/editMessageMedia'))
        self.assertIn(b'attach://photo',request.data)
        for value in ('/etc/shadow','https://attacker.invalid/image.png',b'not png'):
            with self.assertRaises(ValueError):api.send_bot_view(55,{**payload,'photo':value})

    def test_certain_rejection_falls_back_to_text_but_uncertain_send_does_not(self):
        from ustar_connect.telegram_api import TelegramError
        from ustar_connect.bot_graphics import presentation
        api=self.api();payload=presentation(view())
        api._call=Mock(side_effect=[TelegramError('api_rejected'),{'message_id':8}])
        api.send_bot_view(55,payload)
        self.assertEqual(['sendPhoto','sendMessage'],[c.args[0] for c in api._call.call_args_list])
        self.assertEqual(view()['buttons'][0]['label'],api._call.call_args_list[-1].args[1]['reply_markup']['inline_keyboard'][0][0]['text'])
        api._call=Mock(side_effect=TelegramError('transport_unavailable',uncertain=True))
        with self.assertRaises(TelegramError):api.send_bot_view(55,payload)
        self.assertEqual(1,api._call.call_count)

    def test_media_to_long_text_sends_a_new_text_response(self):
        api=self.api();api._call=Mock(return_value={'message_id':8})
        payload={'text':'Полный длинный отчёт','reply_markup':{'inline_keyboard':[]}}
        api.edit_bot_view(55,7,payload,media_message=True)
        self.assertEqual('sendMessage',api._call.call_args.args[0])
