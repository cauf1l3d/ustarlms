"""Owner-visible regressions in the external graphics proposal."""
import sys
from pathlib import Path
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'connect-worker'))
from ustar_connect import bot_graphics as g

def view(**values):
    return {'title':'Сводка по задачам','text':'Выберите задачу.','rows':[],
        'metrics':[],'buttons':[],**values}

def button(label,index=0):
    return {'label':label,'callback':'cb4_'+chr(65+index)*42+'A'}

class BrandRegressions(unittest.TestCase):
    def test_opaque_glyph_stays_opaque_at_each_palette_quality(self):
        faces,masks=g._font();ascent,glyphs=faces['32']
        _,left,top,width,height,offset=glyphs['M']
        i=next(i for i in range(width*height) if masks[offset+i]==15)
        for levels in (16,8,4):
            canvas=g.Canvas(100,levels=levels);canvas.text(20,10,'M')
            x=20+left+i%width;y=10+ascent+top+i//width
            self.assertEqual(tuple(bytes.fromhex(g.TEXT[1:])),canvas.palette[canvas.pixels[y*g.WIDTH+x]],levels)

    def test_hr_create_actions_are_not_relabelled_as_existing_requests(self):
        data=view(title='👤 Кадровые заявки',rows=[['Иван','На согласовании']],
            buttons=[button('➕ Заявка на найм'),button('➖ Заявка на увольнение',1),
                button('Иван · Подтверждение регистрации',2),button('Главное меню',3)])
        result=g.presentation(data,now=1790000000)
        keys=result['reply_markup']['inline_keyboard']
        self.assertEqual(['➕ Заявка на найм','➖ Заявка на увольнение','1 · Открыть заявку','Главное меню'],[r[0]['text'] for r in keys])
        self.assertEqual([b['callback'] for b in data['buttons']],[r[0]['callback_data'] for r in keys])

    def test_shortened_list_explains_visible_count_and_keeps_hidden_button_labels(self):
        data=view(rows=[['Сотрудник '+str(i),'Просрочено'] for i in range(8)],
            buttons=[button('Задание '+str(i),i) for i in range(8)],
            metrics=[{'label':'Просрочено','value':5,'total':8} for _ in range(5)])
        result=g.presentation(data,now=1790000000)
        self.assertIn('photo',result)
        self.assertRegex(result['caption'],r'Показаны первые \d+ из 8')
        keys=result['reply_markup']['inline_keyboard']
        shown=sum(' · Открыть задачу' in r[0]['text'] for r in keys)
        self.assertGreater(shown,0);self.assertLess(shown,8)
        self.assertIn('Показаны первые '+str(shown)+' из 8',result['caption'])
        self.assertEqual(data['buttons'][shown]['label'],keys[shown][0]['text'])

    def test_palette_retry_includes_footer(self):
        original=g.Canvas.color
        def fail_footer(canvas,color):
            if color==g.GOLD_DIM and canvas.levels==16:raise ValueError('card_palette_limit')
            return original(canvas,color)
        with patch.object(g.Canvas,'color',fail_footer):
            self.assertIn('photo',g.presentation(view(),now=1790000000))

    def test_negative_status_is_not_success_and_review_is_not_warning(self):
        self.assertEqual(g.RED,g._severity('Не подтверждено'))
        self.assertEqual(g.RED,g._severity('Не принято'))
        self.assertEqual(g.BLUE,g._severity('На согласовании'))
        self.assertEqual(g.GREEN,g._severity('Одобрено'))
        self.assertEqual(g.GREEN,g._severity('Согласовано'))
        self.assertEqual(g.AMBER,g._severity('Истекает срок'))

    def test_long_metric_label_and_value_do_not_overlap(self):
        result=g.presentation(view(metrics=[{'label':'Очень длинная подпись показателя','value':2147483647,'total':2147483647}]),now=1790000000)
        self.assertIn('photo',result)

    def test_pending_registration_does_not_get_success_colour_from_request_title(self):
        circles=[];original=g.Canvas.circle
        def circle(canvas,x,y,radius,color):
            circles.append(color);return original(canvas,x,y,radius,color)
        data=view(title='Кадровые заявки',rows=[['Сотрудник','На согласовании']],
            buttons=[button('Сотрудник · Подтверждение регистрации')])
        with patch.object(g.Canvas,'circle',circle):
            self.assertIn('photo',g.presentation(data,now=1790000000))
        self.assertIn(g.BLUE,circles)
        self.assertNotIn(g.GREEN,circles)

if __name__=='__main__':unittest.main()
