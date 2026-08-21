"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { ArrowRight, CheckCircle2, Gamepad2, RotateCcw, Trophy, XCircle } from "lucide-react";
import { ws } from "@/lib/wsclient";
import { Empty, PageTitle } from "@/components/ui";

interface Game { id: number; code: string; title: string; description: string; type: string; difficulty: number; questionCount: number; attempts: number; correct: number; xp: number }
interface Question { id: number; gameid: number; gameTitle: string; text: string; imageUrl: string; options: string[]; xpReward: number }
interface Answer { correct: boolean; correctOption: number; explanation: string; xpEarned: number; totalGameXp: number; masteredBefore: boolean }

const gameTypeLabel=(type:string)=>({image_quiz:"Фото · инструмент",trick_quiz:"С подвохом",scenario:"Ситуация",quiz:"Квиз"}[type]||"Квиз");

export default function GamesPage() {
  const [games, setGames] = useState<Game[] | null>(null);
  const [totalXp, setTotalXp] = useState(0);
  const [selected, setSelected] = useState<Game | null>(null);
  const [question, setQuestion] = useState<Question | null>(null);
  const [answer, setAnswer] = useState<Answer | null>(null);
  const [picked, setPicked] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  const loadGames = () => ws<{ games: Game[]; totalGameXp: number }>("games").then((d) => { setGames(d.games); setTotalXp(d.totalGameXp); });
  useEffect(() => { loadGames(); }, []);

  const nextQuestion = async (game: Game) => {
    setSelected(game); setAnswer(null); setPicked(null); setBusy(true);
    try { const d = await ws<{ question: Question | null }>("game_question", { gameid: game.id }); setQuestion(d.question); }
    finally { setBusy(false); }
  };
  const submit = async (index: number) => {
    if (!question || answer) return;
    setPicked(index); setBusy(true);
    try { const d = await ws<Answer>("game_answer", { questionid: question.id, option: index }); setAnswer(d); setTotalXp(d.totalGameXp); loadGames(); }
    finally { setBusy(false); }
  };

  if (!games) return <Empty title="Загружаем Game Hub" text="Подбираем игры для вашего отдела…" mascot />;

  if (selected) {
    return <>
      <PageTitle kicker="USTAR Game Hub" title={selected.title} subtitle="Короткий раунд. Правильный ответ проверяется на сервере; XP за один вопрос начисляется только один раз." action={<button className="btn btn-quiet" onClick={() => { setSelected(null); setQuestion(null); setAnswer(null); }}>Все игры</button>} />
      {!question ? <Empty title={busy ? "Готовим вопрос" : "Нет вопросов"} text={busy ? "Секунду…" : "Суперадмин ещё не добавил активные вопросы в эту игру."} mascot /> : <div className="mx-auto max-w-3xl">
        <div className="card overflow-hidden"><div className="industrial-rule"/><div className="p-5 sm:p-7">
          <div className="mb-5 flex items-center justify-between"><span className="pill bg-brand text-white"><Gamepad2 size={13}/> раунд</span><span className="text-sm font-black">+{question.xpReward} XP</span></div>
          {question.imageUrl ? <div className="relative mb-6 aspect-[16/9] overflow-hidden rounded-xl border border-black/10 bg-page"><img src={question.imageUrl} alt="Изображение вопроса" className="h-full w-full object-contain" /></div> : <div className="relative mb-6 h-44 overflow-hidden rounded-xl bg-brand"><Image src="/brand/mascot-thinking.png" alt="Маскот USTAR" fill className="object-contain p-4 opacity-80" sizes="180px"/><div className="absolute bottom-5 left-5 max-w-sm text-sm font-bold text-white/70">Для фото-вопроса добавьте URL изображения в редакторе Game Hub.</div></div>}
          <h2 className="text-2xl font-black leading-tight tracking-[-.025em]">{question.text}</h2>
          <div className="mt-6 grid gap-3 sm:grid-cols-2">{question.options.map((option, index) => {
            let cls = "border-black/10 bg-white";
            if (answer) {
              if (index === answer.correctOption) cls = "border-green-500 bg-green-50";
              else if (index === picked && !answer.correct) cls = "border-red-400 bg-red-50";
            } else if (picked === index) cls = "border-brand bg-accentSoft";
            return <button disabled={busy || !!answer} key={index} onClick={() => submit(index)} className={`min-h-[64px] rounded-xl border-2 p-4 text-left text-sm font-bold transition hover:border-brand ${cls}`}><span className="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-md bg-brand text-[11px] text-white">{String.fromCharCode(65 + index)}</span>{option}</button>;
          })}</div>
          {answer && <div className={`mt-6 overflow-hidden rounded-xl border ${answer.correct ? "border-green-200 bg-green-50" : "border-red-200 bg-red-50"}`}><div className="grid items-center sm:grid-cols-[96px_1fr]"><div className="relative hidden h-full min-h-28 sm:block"><Image src={answer.correct?"/brand/mascot-success.png":"/brand/mascot-thinking.png"} alt="Реакция маскота USTAR" fill className="object-contain p-2" sizes="96px"/></div><div className="flex items-start gap-3 p-4">{answer.correct ? <CheckCircle2 className="mt-0.5 shrink-0 text-green-700"/> : <XCircle className="mt-0.5 shrink-0 text-red-700"/>}<div><div className="font-black">{answer.correct ? "Верно" : "Есть подвох"}{answer.xpEarned > 0 && ` · +${answer.xpEarned} XP`}</div><p className="mt-1 text-sm leading-6 text-mut">{answer.explanation || "Посмотрите на правильный вариант и закрепите логику ответа."}</p>{answer.masteredBefore && answer.correct && <p className="mt-1 text-xs font-bold text-mut">Этот вопрос уже был освоен — повторный XP не начисляется.</p>}</div></div></div></div>}
          <div className="mt-6 flex justify-between border-t border-black/10 pt-5"><div><div className="eyebrow">Игровой XP</div><div className="mt-1 text-2xl font-black">{totalXp}</div></div>{answer && <button className="btn btn-accent" onClick={() => nextQuestion(selected)}><RotateCcw size={16}/> Следующий</button>}</div>
        </div></div>
      </div>}
    </>;
  }

  return <>
    <PageTitle kicker="Микрообучение" title="Game Hub" subtitle="Короткие игровые раунды для инструментов, клиентских ситуаций, стандартов и вопросов с подвохом. Игра закрепляет знания, но не заменяет курс." />
    <section className="card mb-6 overflow-hidden bg-brand text-white"><div className="industrial-rule"/><div className="grid md:grid-cols-[1fr_240px]"><div className="p-6 sm:p-8"><div className="eyebrow !text-accent">Ваш результат</div><div className="mt-3 flex items-end gap-3"><span className="text-5xl font-black tracking-[-.06em]">{totalXp}</span><span className="pb-1 text-sm font-bold text-white/55">GAME XP</span></div><p className="mt-4 max-w-lg text-sm leading-6 text-white/65">XP начисляется за первое правильное решение каждого вопроса. Можно переигрывать без накрутки рейтинга.</p></div><div className="relative hidden md:block"><Image src="/brand/mascot-success.png" alt="Маскот USTAR" fill className="object-contain p-4" sizes="240px"/></div></div></section>
    {games.length === 0 ? <Empty title="Игры ещё не опубликованы" text="Суперадмин может создать постоянные игровые наборы в панели управления." mascot /> : <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{games.map((g) => <button key={g.id} onClick={() => nextQuestion(g)} className="card card-hover overflow-hidden text-left"><div className="flex h-24 items-end justify-between bg-brand p-4 text-white"><span className="pill bg-accent text-brand">{gameTypeLabel(g.type)}</span><Trophy size={20} className="text-accent"/></div><div className="p-5"><div className="eyebrow">{g.questionCount} вопросов · сложность {g.difficulty}/5</div><h2 className="mt-2 text-xl font-black">{g.title}</h2><p className="mt-2 min-h-12 text-sm leading-6 text-mut">{g.description}</p><div className="mt-5 flex items-center justify-between border-t border-black/10 pt-4"><span className="text-xs font-bold text-mut">Ваш XP: {g.xp}</span><span className="flex items-center gap-1 text-sm font-black">Играть <ArrowRight size={16}/></span></div></div></button>)}</div>}
  </>;
}
