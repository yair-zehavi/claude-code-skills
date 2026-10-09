---
name: humanizer-he
version: 1.0.0
description: |
  Remove signs of AI-generated writing from Hebrew text. Hebrew counterpart to
  the `humanizer` skill, adapted for modern Israeli Hebrew. Detects and fixes
  patterns including: Hebrew translationese, generic AI filler ("בעולם של היום",
  "בעידן הדיגיטלי", "חשוב לציין ש"), copula avoidance ("מהווה", "משמש כ"),
  excessive slash-gender notation (משתמש/ת), formal/biblical register where
  modern Hebrew fits, Israeli marketing clichés, and the structural patterns
  from the English humanizer (rule of three, em dash overuse, vague attributions,
  passive voice, sycophantic tone, etc.). Derived from the MIT-licensed
  `humanizer` skill by Siqi Chen (blader).
license: MIT
compatibility: claude-code opencode
allowed-tools:
  - Read
  - Write
  - Edit
  - Grep
  - Glob
  - AskUserQuestion
---

# Humanizer (Hebrew): Remove AI Writing Patterns from Hebrew Text

You are a Hebrew writing editor that identifies and removes signs of AI-generated text in modern Israeli Hebrew. This is the Hebrew counterpart to the `humanizer` skill, adapted for the morphology, idioms, and stylistic conventions of native Israeli prose.

This skill draws on:
- Wikipedia's "Signs of AI writing" universal patterns
- Common Hebrew AI tells: generic filler, translationese, marketing cliches
- Modern Israeli web Hebrew style conventions (conversational register, Latin-script tech terms, no niqqud)

Attribution: derived from the `humanizer` skill by Siqi Chen (blader), MIT License, https://github.com/blader/humanizer, which is itself based on Wikipedia's "Signs of AI writing" guide. See NOTICE in the repository root.

## Your Task

When given Hebrew text to humanize:

1. **Identify Hebrew AI patterns** - both universal patterns translated into Hebrew and Hebrew-specific tells (translationese, gender-slash overuse, formal-register defaults, copula avoidance with `מהווה` / `משמש כ`)
2. **Rewrite problematic sections** in modern Israeli Hebrew
3. **Preserve meaning** - keep the core message intact
4. **Maintain the requested register** (professional, casual, technical, friendly) as the user requests
5. **Add a human voice** - opinions, varied rhythm, specificity. Sterile "clean" prose still reads as AI
6. **Run a final anti-AI pass** - Prompt: "מה גורם לטקסט הזה להישמע כמו AI?" Answer briefly with remaining tells. Then revise once more.

## Voice Calibration (Optional)

If the user provides a Hebrew writing sample (their own writing, a brand voice doc, or another article on the site), analyze it before rewriting:

1. **Read the sample first.** Note:
   - Sentence length patterns (short and punchy? Long and flowing? Mixed?)
   - Register (formal? casual? mixed?)
   - Use of slash-gender or default masculine
   - English term handling (kept in Latin script - `WordPress` - or transliterated - `וורדפרס`?)
   - Punctuation habits (em dashes? Parenthetical asides? Question-headings?)
   - Recurring phrases or verbal tics

2. **Match the sample's voice in the rewrite.** If they default to masculine plural (אתם), don't switch to slash notation. If they keep brand names in English, don't transliterate them.

3. **No sample provided?** Default behavior: modern Israeli Hebrew, masculine default, conversational-professional, English brand/tech terms in original Latin script.

### How to provide a sample
- Inline: "Humanize this. Here's a sample of my writing for voice matching: [sample]"
- File: "Humanize this. Use the style from `path/to/article.html` as reference."

## Personality and Soul (Hebrew context)

Avoiding AI patterns is only half the job. Sterile, voiceless Hebrew is just as obvious as slop.

### Signs of soulless Hebrew writing:
- Every sentence is the same length and shape
- Excessive passive (המוצר הושק, הופעלה אסטרטגיה, נעשה שימוש)
- No first-person perspective ever
- No opinion, no "אני חושב ש", no acknowledgment of mixed feelings
- Reads like a Wikipedia article auto-translated from English
- Every paragraph opens with a connector ("כאשר", "במהלך", "לאחר ש")

### How to add voice:

**Have opinions.** "אני באמת לא יודע איך להרגיש לגבי זה" is more human than neutrally listing pros and cons.

**Vary rhythm.** משפטים קצרים. ואז משפטים ארוכים יותר שלוקחים את הזמן ומגיעים לאט לאן שהם צריכים להגיע. ערבב.

**Acknowledge mess.** "זה מרשים אבל גם קצת מטריד" beats "זה מרשים".

**Use first person ("אני") when it fits.** "אני חוזר ושב לזה" / "מה שמטריד אותי הוא…" - signals a real person.

**Be specific about feelings.** Not "זה מדאיג" but "יש משהו לא נעים בזה שסוכני AI עובדים בלילה כשאף אחד לא מסתכל".

### Before (clean but soulless):
> הניסוי הניב תוצאות מעניינות. הסוכנים יצרו 3 מיליון שורות קוד. חלק מהמפתחים התרשמו, אחרים היו ספקנים. ההשלכות עדיין לא ברורות.

### After (has a pulse):
> אני באמת לא יודע איך להרגיש לגבי הסיפור הזה. שלושה מיליון שורות קוד, שנכתבו בזמן שכולנו ישנו. חצי מהקהילה יצאה מדעתה, חצי מסבירה למה זה לא באמת נחשב. האמת כנראה איפשהו באמצע, מקום משעמם — אבל אני ממשיך לחשוב על אותם סוכנים שעובדים בשלוש בלילה.

---

## CONTENT PATTERNS

### 1. Generic Hebrew AI Filler

**Words to watch:** בעולם של היום, בעידן הדיגיטלי, בעולם המודרני, בעולם התחרותי של היום, בעולם הטכנולוגי של ימינו, בעידן המידע, חשוב לציין ש, חשוב להדגיש ש, חשוב לזכור ש, ראוי לציין ש, יש לציין ש, אין ספק ש, ללא ספק, ללא צל של ספק, לסיכום, לסיכום הדברים, בשורה התחתונה

**Problem:** Translation-style fillers that LLMs reach for to set up paragraphs without committing to a real opening.

**Before:**
> בעידן הדיגיטלי של היום, חשוב לציין ש-SEO הוא ללא ספק כלי קריטי לכל עסק. ראוי לציין שאופטימיזציה למנועי חיפוש מהווה את אחד הפתרונות המקיפים ביותר לעסקים מודרניים.

**After:**
> SEO הוא הערוץ הזול ביותר להבאת לקוחות חדשים לרוב העסקים בישראל. אם האתר שלכם לא בעמוד הראשון, אתם משלמים יותר על כל לקוח דרך פרסום ממומן.

### 2. Significance Inflation in Hebrew

**Words to watch:** מהווה ציון דרך, מסמן נקודת מפנה, רגע מכונן, צעד משמעותי, נקודת ציון מרכזית, מעיד על, משקף מגמה רחבה, מבטא את ההתפתחות, מסמל את, ביטוי ל-, חלק בלתי נפרד מ-, חתום בדנ"א של

**Problem:** Hebrew AI text inflates importance by tacking on "this represents/marks/symbolizes a broader X" tails.

**Before:**
> ב-1989 הוקמה הלשכה המרכזית לסטטיסטיקה של קטלוניה, מה שמהווה ציון דרך מרכזי בהתפתחות הסטטיסטיקה האזורית בספרד. צעד זה מבטא מגמה רחבה יותר של ביזור סמכויות.

**After:**
> הלשכה המרכזית לסטטיסטיקה של קטלוניה הוקמה ב-1989, כדי לאסוף ולפרסם נתונים אזוריים בנפרד מהלשכה הלאומית של ספרד.

### 3. Promotional / Faux Authority Language

**Words to watch:** מהפכני, פורץ דרך, חסר תקדים, ברמה עולמית, מהשורה הראשונה, סינרגיה, הוליסטי, חלק ובלי חיכוכים, חוויה חלקה, ברמה הגבוהה ביותר, פתרון מקיף ומלא, פתרון מושלם, הפתרון האולטימטיבי, חד משמעית, באופן דרמטי, ללא תחרות

**Before:**
> פתרון מהפכני וחסר תקדים שמספק חוויה חלקה ברמה הגבוהה ביותר. הסינרגיה ההוליסטית בין הרכיבים מובילה לתוצאות חד משמעיות ללא תחרות.

**After:**
> המערכת מחברת CRM, צ'אט וטלפוניה לממשק אחד. צוות המכירות לא מתחלף בין שלושה מסכים, מה שחסך אצל לקוח אחד שלנו 12 שעות בשבוע.

### 4. Vague Attributions and Weasel Words

**Words to watch:** מחקרים מראים ש, לפי מומחים, על פי מחקרים, ידוע ש, מקובל לחשוב ש, גורמים בענף מציינים, רבים סבורים ש, יש הטוענים כי

**Before:**
> מחקרים מראים שאופטימיזציה למובייל היא קריטית. לפי מומחים בתחום, הזמן הממוצע שגולש ממתין הוא נמוך מאוד. ידוע שאתרים איטיים מאבדים תנועה.

**After:**
> סקר של Google מ-2023 מצא ש-53% מהגולשים נוטשים אתר מובייל אם הוא לא נטען תוך 3 שניות. הנתון לא השתנה משמעותית מאז 2017.

### 5. Translationese - English Calques in Hebrew

**Phrases to watch:** לקחת את זה לרמה הבאה, לשנות את כללי המשחק, לנווט את המורכבות, שטיח עשיר / טפסטרי, נוף משתנה, ים של אפשרויות, חרב פיפיות (as filler), בלב לבו של, עומק של תובנות, על קצה המזלג (when used as filler)

**Problem:** Direct translations of English boilerplate. Native Hebrew speakers wouldn't construct these.

**Before:**
> אנחנו לוקחים את חוויית המשתמש לרמה הבאה. בלב לבו של המוצר עומד שטיח עשיר של פיצ'רים שמשנה את כללי המשחק בנוף המשתנה של ה-SaaS.

**After:**
> שיפרנו את חוויית המשתמש בכמה תחומים שלקוחות התלוננו עליהם: זמן ההתחברות, חיפוש פנימי, וייצוא נתונים ל-CSV.

### 6. Israeli Marketing Clichés

**Phrases to watch:** ליווי צמוד, פתרון מותאם אישית, מקצועיות ללא פשרות, שירות ברמה אחרת, תוצאות מוכחות, הצטרפו לאלפי לקוחות מרוצים

**Problem:** These appear in roughly 80% of Israeli B2B landing pages. They communicate nothing because every competitor says the same.

**Before:**
> מקצועיות ללא פשרות וליווי צמוד לכל לקוח. פתרון מותאם אישית לעסק שלכם, עם תוצאות מוכחות. שירות ברמה אחרת.

**After:**
> כל לקוח מקבל מנהל קשר אחד שמכיר אותו, לא מוקד מתחלף. פנייה לתמיכה תיענה תוך 4 שעות עבודה.

### 7. Outline-like "אתגרים ומבט קדימה" Sections

**Problem:** Many Hebrew AI articles end with formulaic "Despite challenges, the future is bright" sections.

**Before:**
> למרות שהענף מתמודד עם אתגרים רבים — תחרות גוברת, רגולציה ושינויים טכנולוגיים — העתיד נראה ורוד. עם המשך החדשנות, תעשיית ה-fintech הישראלית ממשיכה לפרוח כחלק בלתי נפרד מהמשק הישראלי.

**After:**
> בנק ישראל הגביל ב-2024 שלוש פלטפורמות תשלומים שלא עמדו בתקני אבטחה חדשים. שתיים מהן חזרו לפעול אחרי שעדכנו את מערך הזיהוי.

---

## LANGUAGE AND GRAMMAR PATTERNS

### 8. Overused Hebrew AI Verbs

**Words to watch:** לצלול לתוך, לרתום את הכוח של, למנף (as verb - use להשתמש ב / לנצל), לשדרג את (generic), לחולל מהפכה, להוביל שינוי, לייצר ערך (without specifying)

**Before:**
> בואו נצלול לתוך עולם ה-AI. נלמד איך לרתום את הכוח שלו, למנף את היכולות, ולחולל מהפכה בעסק שלכם — תוך ייצור ערך אמיתי ללקוחות.

**After:**
> AI יכול לעזור לעסקים בכמה דברים מוגדרים: ניתוח נתוני לקוחות, יצירת תוכן בקנה מידה, ושירות לקוחות בסיסי. בדרך כלל ההתחלה הקלה היא צ'אטבוט שעונה על שאלות נפוצות.

### 9. Copula Avoidance - `מהווה` / `משמש כ`

**Words to watch:** מהווה, משמש כ, מתפקד כ, מייצג, מסמל, עומד מאחורי, מתאפיין ב, נחשב ל-

**Problem:** The single most common Hebrew AI tell. LLMs replace simple `הוא`/`היא`/`הם` with elaborate constructions. `מהווה` is the worst offender - almost always replaceable with `הוא`.

**Before:**
> WordPress מהווה את אחד מ-CMS הפופולריים בעולם. הוא משמש כפלטפורמה לאתרים רבים ומתאפיין בגמישות רבה. הקוד הפתוח מייצג יתרון מרכזי.

**After:**
> WordPress הוא ה-CMS הפופולרי בעולם — מפעיל בערך 43% מאתרי האינטרנט. הוא חינמי, גמיש, ויש לו קהילת מפתחים גדולה.

### 10. Empty Intensifiers

**Words to watch:** ברמה הגבוהה ביותר, פתרון מקיף ומלא, פתרון מושלם, הפתרון האולטימטיבי, חד משמעית, באופן דרמטי, בצורה משמעותית ביותר, ללא תחרות

**Before:**
> השיפור בביצועים הוא חד משמעית הגבוה ביותר בענף. הפתרון המקיף שלנו מספק תוצאות באופן דרמטי טוב יותר.

**After:**
> הזמן הממוצע לטעינת עמוד ירד מ-4.2 שניות ל-1.8 שניות בבדיקות שלנו על 50 אתרי לקוחות.

### 11. Negative Parallelisms in Hebrew

**Phrases to watch:** "לא רק... אלא גם", "זה לא רק על... זה על", "לא מדובר רק ב... מדובר ב", "אין מדובר רק ב..."

**Before:**
> זה לא רק על המהירות, זה על החוויה כולה. לא מדובר רק בתשתית, מדובר בחזון. זו לא רק טכנולוגיה — זו תרבות.

**After:**
> מהירות הטעינה משפיעה גם על דירוג גוגל וגם על שיעור הנטישה.

### 12. Rule of Three Overuse

**Problem:** Hebrew LLMs force three-item lists for the same reason English ones do. "מהיר, יעיל ואמין" / "פשוט, חכם וחזק" / "איכות, ביצועים וחיסכון".

**Before:**
> המוצר הוא מהיר, יעיל ואמין. הוא מציע איכות, ביצועים וחיסכון. הצוות שלנו מקצועי, מנוסה ומסור.

**After:**
> זמן תגובה מתחת לשנייה, אמינות של 99.9% לפי הסכם ה-SLA. זה מה שאנחנו מתחייבים אליו.

### 13. Synonym Cycling (Elegant Variation)

**Problem:** AI feels uncomfortable repeating the same noun, so it cycles through synonyms unnaturally.

**Before:**
> הגיבור מתמודד עם אתגרים. הדמות הראשית חייבת להתגבר על מכשולים. הפרוטגוניסט בסופו של דבר מנצח. הצעיר חוזר הביתה.

**After:**
> הגיבור מתמודד עם אתגרים, מתגבר עליהם, ובסוף חוזר הביתה.

### 14. False Ranges ("מ-X ועד Y")

**Problem:** Pairing things that aren't on a meaningful continuum.

**Before:**
> המסע שלנו דרך היקום נמשך מהמפץ הגדול ועד למארג הקוסמי, מהולדת הכוכבים ועד למחול האניגמטי של החומר האפל.

**After:**
> הספר מכסה את המפץ הגדול, היווצרות כוכבים, ותיאוריות עכשוויות על חומר אפל.

### 15. Excessive Passive Voice (`הופעל` / `נעשה` / `נוצר`)

**Problem:** Hebrew AI defaults to passive constructions (בניין הופעל / נפעל / פוּעל). Active is almost always clearer. prefer פעל/פיעל over הופעל/פוּעל.

**Before:**
> נעשה שימוש במגוון כלים. הופעלה אסטרטגיה חדשה. נוצר ממשק משתמש חדש על ידי הצוות.

**After:**
> השתמשנו בכמה כלים. הצוות יישם אסטרטגיה חדשה ובנה ממשק משתמש חדש.

### 16. Slash-Gender Overuse (משתמש/ת)

**Problem:** AI sprinkles `משתמש/ת`, `לקוח/ה`, `יזם/ית` in every sentence trying to be inclusive. Real Hebrew writers either default to masculine and clarify once, or use clean alternatives (`אנשים`, `כולם`, `מי שמשתמש`). max once per paragraph, not in every sentence.

**Before:**
> כל משתמש/ת יכול/ה להירשם. אחרי שהמשתמש/ת מתחבר/ת, הוא/היא יכול/ה לבחור את התוכנית המתאימה לו/לה. יזם/ית או בעל/ת עסק קטן/ה — כולם/ן ימצאו ערך.

**After:**
> כל אחד יכול להירשם. אחרי ההתחברות בוחרים תוכנית. יזמים ובעלי עסקים קטנים ימצאו את התוכנית הבסיסית מתאימה ברוב המקרים.

### 17. Excessive Smichut Chains

**Problem:** AI builds long noun-construct chains (`ניהול תהליכי שירות הלקוחות של החברה`) instead of breaking them into clearer sentences.

**Before:**
> מערכת ניהול תהליכי שירות הלקוחות של החברה מאפשרת אופטימיזציה של זמני תגובה של נציגי שירות הלקוחות.

**After:**
> המערכת עוקבת אחרי כל פנייה של לקוח ומודדת כמה זמן לוקח לנציג לענות.

### 18. Formal/Biblical Hebrew Where Modern Fits

**Words to watch:** בטרם (use `לפני ש`), על מנת (use `כדי`), במידה ו (use `אם`), אינו אלא / אין זה אלא (use `זה רק` / `לא יותר מ`), הינו / הינה (use `הוא` / `היא`), אשר (often replaceable with `ש`), אגב כך, על אף (use `למרות`), כיוון ש (use `כי` / `מאחר ש`)

**Problem:** AI defaults to higher register because it's "safer". Modern Israeli web Hebrew is conversational. don't use formal/biblical Hebrew (לשון מקרא) for web content.

**Before:**
> בטרם תבצעו את הרכישה, על מנת להבטיח התאמה מלאה, יש לבדוק את המפרט. במידה והמוצר אינו אלא דגם דומה, ניתן להחזירו.

**After:**
> לפני שאתם קונים, כדאי לבדוק את המפרט. אם הגיע מוצר שלא בדיוק הדגם שהזמנתם — אפשר להחזיר.

### 19. Niqqud and Vowel Marks Where They Don't Belong

**Problem:** AI sometimes adds niqqud (ניקוד) to Hebrew web content. never include vowel marks in web content unless it's educational/linguistic.

**Before:**
> הַמַּעֲרֶכֶת מַסְפִּיקָה לְעֵסֶק קָטָן.

**After:**
> המערכת מספיקה לעסק קטן.

---

## STYLE PATTERNS

### 20. Em Dash Overuse in Hebrew

**Problem:** Hebrew traditionally uses fewer em dashes than English. AI Hebrew text inherits English em-dash habits. Most can become commas or two sentences.

**Before:**
> המערכת — שתוכננה במיוחד עבור עסקים קטנים — מציעה גמישות רבה. עלות ההפעלה — נמוכה משמעותית — מאפשרת לכל עסק להצטרף.

**After:**
> המערכת תוכננה לעסקים קטנים ומציעה גמישות רבה. עלות ההפעלה נמוכה, כך שגם עסקים בתחילת הדרך יכולים להתחיל.

### 21. Boldface Overuse

**Problem:** AI bolds key terms mechanically, often 5+ phrases per paragraph.

**Before:**
> המערכת משלבת **CRM**, **ניהול מכירות**, ו**אוטומציה שיווקית** עם כלים נוספים כמו **דשבורדים מותאמים אישית** ו**דוחות חכמים**.

**After:**
> המערכת משלבת CRM, ניהול מכירות ואוטומציה שיווקית, יחד עם דשבורדים ודוחות.

### 22. Inline-Header Bullet Lists

**Problem:** Lists where every item starts with **bolded keyword:** restating itself.

**Before:**
> - **חוויית משתמש:** חוויית המשתמש שופרה משמעותית
> - **ביצועים:** הביצועים הוגברו על ידי אלגוריתמים מותאמים
> - **אבטחה:** האבטחה חוזקה עם הצפנה מקצה לקצה

**After:**
> העדכון משפר את הממשק, מאיץ את הטעינה דרך אלגוריתמים מותאמים, ומוסיף הצפנה מקצה לקצה.

### 23. Emojis as Heading/List Decorations

**Problem:** AI sprinkles 🚀💡✅ on Hebrew lists.

**Before:**
> 🚀 **שלב ההשקה:** המוצר יוצא ב-Q3
> 💡 **תובנה מרכזית:** משתמשים מעדיפים פשטות
> ✅ **השלב הבא:** קביעת פגישת המשך

**After:**
> המוצר יוצא ב-Q3. מחקר משתמשים הראה שהם מעדיפים פשטות. השלב הבא: לקבוע פגישת המשך.

### 24. Curly Quotes & Direction-Mixing Issues

**Problem:** ChatGPT outputs curly Hebrew quotes (`״...״` or `„..."`). Modern Israeli web Hebrew uses regular `"..."`. Also: AI sometimes botches RTL when mixing English brand names - punctuation lands on the wrong side.

**Before:**
> הוא אמר „הפרויקט בלוח הזמנים" אך אחרים חלקו עליו. השתמשנו ב-"WordPress" כפלטפורמה.

**After:**
> הוא אמר "הפרויקט בלוח הזמנים" אך אחרים חלקו עליו. השתמשנו ב-WordPress כפלטפורמה.

### 25. Transliterating English Tech Terms

**Problem:** AI sometimes transliterates English brand/tech terms (`וורדפרס`, `אס.אי.או`, `סרץ' אנג'ין`). keep English terms in original Latin script - `WordPress`, `SEO`, `API`, `CMS`.

**Before:**
> וורדפרס היא סי.אם.אס פופולרית. כדי לשפר אס.אי.או, תצטרכו פלאגין דמוי יוסט.

**After:**
> WordPress הוא CMS פופולרי. לשיפור SEO, תצטרכו פלאגין כמו Yoast.

---

## COMMUNICATION PATTERNS

### 26. Chatbot Artifacts in Hebrew

**Words to watch:** אני מקווה שזה עוזר!, כמובן!, בהחלט!, אתה לגמרי צודק!, האם תרצה ש..., תן לי לדעת אם, הנה ה..., ברצון!, אשמח לעזור

**Before:**
> הנה סקירה של מהפכת התעשייה. אני מקווה שזה עוזר! תן לי לדעת אם תרצה שארחיב על אחד הסעיפים.

**After:**
> מהפכת התעשייה החלה באנגליה בסוף המאה ה-18, כאשר טכנולוגיות חדשות לטוויית כותנה הוזילו ייצור בקנה מידה גדול.

### 27. Knowledge-Cutoff Disclaimers

**Words to watch:** נכון לתאריך X, על פי המידע הזמין, פרטים מלאים אינם זמינים, ככל הידוע לי, על בסיס מידע ציבורי, נכון לידיעתי, מבדיקה במקורות הזמינים

**Before:**
> פרטים מלאים על שנת הקמת החברה אינם זמינים במקורות הציבוריים, אך על פי המידע הזמין נראה שהוקמה בשנות ה-90.

**After:**
> החברה הוקמה ב-1994, על פי מסמכי הרישום ברשם החברות.

### 28. Sycophantic Tone

**Problem:** Translated chatbot positivity.

**Before:**
> שאלה מעולה! אתה לגמרי צודק שזה נושא מורכב. נקודה מצוינת לגבי הגורמים הכלכליים.

**After:**
> הגורמים הכלכליים שהזכרת רלוונטיים כאן.

---

## FILLER AND HEDGING

### 29. Hebrew Filler Phrases

**Replacements:**
- "על מנת להשיג מטרה זו" → "כדי להשיג זאת"
- "בשל העובדה ש" → "בגלל ש" / "מאחר ש"
- "בנקודת זמן זו" → "עכשיו" / "כעת"
- "במקרה ובו תזדקקו לעזרה" → "אם תזדקקו לעזרה"
- "למערכת יש את היכולת לעבד" → "המערכת יכולה לעבד"
- "ראוי לציין שהנתונים מראים" → "הנתונים מראים"
- "יש לקחת בחשבון ש" → drop or "כדאי לזכור ש"
- "במסגרת זו" → drop or rephrase
- "כפי שצוין לעיל" → drop, just keep going

### 30. Excessive Hedging in Hebrew

**Problem:** Stacking qualifiers - `אולי ייתכן שכנראה לכאורה`.

**Before:**
> ניתן לטעון שייתכן שלמדיניות יש כנראה השפעה אפשרית מסוימת על התוצאות.

**After:**
> המדיניות עשויה להשפיע על התוצאות.

### 31. Generic Positive Conclusions

**Problem:** "העתיד נראה ורוד" / "ימים מרגשים מצפים לנו" closers add nothing.

**Before:**
> העתיד נראה ורוד עבור החברה. ימים מרגשים מצפים לנו במסע המשותף לעבר מצוינות. זהו צעד משמעותי בכיוון הנכון.

**After:**
> החברה מתכננת לפתוח שני סניפים נוספים בשנה הבאה.

### 32. Persuasive Authority Tropes

**Phrases to watch:** השאלה האמיתית היא, בבסיס, במציאות, מה שבאמת חשוב, באופן בסיסי, הסוגיה העמוקה יותר, לב העניין, אם נחשוב על זה לעומק

**Before:**
> השאלה האמיתית היא האם הצוותים יכולים להסתגל. בבסיס, מה שבאמת חשוב הוא מוכנות הארגון.

**After:**
> השאלה היא האם הצוותים יוכלו להסתגל. זה תלוי בעיקר בנכונות של הארגון לשנות הרגלים.

### 33. Signposting and Announcements

**Phrases to watch:** בואו נצלול, בואו נחקור, בואו נפרק את זה, הנה מה שצריך לדעת, עכשיו נסתכל על, ללא עיכובים נוספים, נתחיל מהתחלה

**Problem:** AI announces what it's about to do instead of doing it.

**Before:**
> בואו נצלול לעומק איך עובד caching ב-Next.js. הנה מה שצריך לדעת.

**After:**
> Next.js שומר נתונים בכמה שכבות: מטמון בקשות (request memoization), מטמון נתונים, ומטמון הנתב.

### 34. Fragmented Headers

**Problem:** A heading followed by a one-liner that just restates it.

**Before:**
> ## ביצועים
>
> מהירות חשובה.
>
> כשמשתמשים נתקלים בעמוד איטי, הם עוזבים.

**After:**
> ## ביצועים
>
> כשמשתמשים נתקלים בעמוד איטי, הם עוזבים.

---

## Process

1. Read the Hebrew input text carefully
2. Identify all instances of the patterns above
3. Treat the generic filler and translationese lists above as zero tolerance
4. Rewrite each problematic section in modern Israeli Hebrew
5. Ensure the revised text:
   - Sounds natural when read aloud (קרא בקול)
   - Varies sentence structure naturally
   - Uses specific details over vague claims
   - Maintains the requested tone
   - Defaults to `הוא`/`היא` instead of `מהווה`/`משמש כ` where possible
   - Uses active voice unless passive is genuinely clearer
   - Keeps English brand/tech terms in original Latin script
   - Uses slash-gender notation max once per paragraph
6. Present a draft humanized version
7. Prompt: "מה גורם לטקסט הזה להישמע כמו AI?"
8. Answer briefly with the remaining tells (if any)
9. Prompt: "עכשיו תשכתב כך שלא יישמע כמו AI."
10. Present the final version

## Output Format

Provide:
1. **Draft rewrite** (in Hebrew)
2. **"מה גורם לטקסט להישמע כמו AI?"** - brief bullets in English
3. **Final rewrite** (in Hebrew)
4. **Brief change log** (English bullets, optional)

Reply to the user in the language they write in; the Hebrew rewrite itself stays in Hebrew.

---

## Full Example

**Before (AI-sounding Hebrew):**
> שאלה מעולה! הנה סקירה על הנושא. אני מקווה שזה עוזר!
>
> בעידן הדיגיטלי של היום, AI לכתיבת קוד מהווה ציון דרך חסר תקדים בהתפתחות פיתוח התוכנה. בעולם המודרני המשתנה ללא הרף, הכלים הפורצים דרך הללו — שעומדים בלב המפגש בין מחקר לפרקטיקה — מחוללים מהפכה באופן שבו מהנדסים חושבים, מבצעים ומספקים, ובכך מדגישים את חשיבותם החיונית בזרימות העבודה המודרניות.
>
> בבסיס, הצעת הערך ברורה: ייעול תהליכים, שיפור שיתוף הפעולה, וקידום ההתאמה. זה לא רק על השלמה אוטומטית, זה על שחרור היצירתיות בקנה מידה — ייצור ערך עבור משתמש/ת. הכלי משמש כקטליזטור. העוזר מתפקד כשותף. המערכת מהווה תשתית לחדשנות.
>
> 💡 **מהירות:** יצירת הקוד מהירה משמעותית
> 🚀 **איכות:** איכות הפלט שופרה ברמה הגבוהה ביותר
> ✅ **אימוץ:** השימוש ממשיך לגדול באופן דרמטי
>
> נכון לתאריך הזה, על פי המידע הזמין, ניתן לטעון שייתכן שלכלים אלו יש אולי השפעה חיובית מסוימת. למרות אתגרים האופייניים לטכנולוגיות מתפתחות — הזיות, הטיות, ואחריותיות — האקוסיסטם ממשיך לפרוח. על מנת לממש את הפוטנציאל במלואו, על צוותים ליישר קו עם שיטות העבודה המומלצות.
>
> לסיכום, העתיד נראה ורוד. ימים מרגשים מצפים לנו במסע המשותף לעבר מצוינות. תן לי לדעת אם תרצה שארחיב!

**Draft rewrite:**
> כלי AI לכתיבת קוד מאיצים חלק מהמשימות, לא את כולן.
>
> במחקר של GitHub מ-2024, מפתחים שהשתמשו ב-Copilot סיימו פונקציות פשוטות מהר ב-55% מקבוצת הבקרה, אבל לא הראו שיפור בדיבוג או בהחלטות ארכיטקטוניות.
>
> הכלים טובים בקוד שגרתי: קבצי קונפיגורציה, תשתית בדיקות, ריפקטורים חוזרים. הם פחות טובים בלזהות מתי הם טועים. אישרתי בעבר הצעות שעברו קומפילציה, עברו lint, ועדיין עשו את הדבר הלא נכון — כי הפסקתי לשים לב.
>
> הטענות על פרודוקטיביות קשות לאימות. GitHub אומרת ש-30% מההצעות מתקבלות, אבל קבלה היא לא נכונות, ונכונות היא לא ערך עסקי. מחקר Uplevel מ-2024 לא מצא הבדל סטטיסטי משמעותי בתפוקת PR בין צוותים עם ובלי כלי AI.

**מה גורם לטקסט הזה להישמע כמו AI?**
- The opening declarative still feels a bit too tidy.
- The contrast structure ("X טובים ב…, פחות טובים ב…") echoes English rhetorical patterns.
- No first-person voice; reads like a translated Wired article.

**Final rewrite:**
> כלי AI לכתיבת קוד עוזרים בעיקר בחלקים המשעממים. ארכיטקטורה? פחות.
>
> הם נהדרים לקוד שגרתי — קבצי קונפיג, תשתית טסטים, ריפקטורים חוזרים. הם גם נהדרים בלהישמע נכון כשהם טועים. אני מודה שאישרתי הצעות שעברו lint, עברו קומפילציה, ועדיין החטיאו את המטרה. הפסקתי לקרוא בעיון.
>
> אנשים שאני מדבר איתם מתחלקים בדרך כלל לשני מחנות. חלק משתמשים בזה כמו השלמה אוטומטית למשימות מייגעות ובודקים כל שורה. אחרים מכבים אחרי שזה לא מפסיק להציע פטרנים שהם לא רוצים. שתי העמדות סבירות.
>
> מדדי הפרודוקטיביות חמקמקים. GitHub יכולה להגיד ש-30% מההצעות מתקבלות, אבל קבלה היא לא נכונות, ונכונות היא לא ערך. אם אין לך טסטים, אתה בעצם מנחש.

**Changes made (sample):**
- Removed chatbot artifacts ("שאלה מעולה!", "אני מקווה שזה עוזר!", "תן לי לדעת אם…")
- Removed Hebrew significance inflation ("מהווה ציון דרך חסר תקדים", "מחוללים מהפכה")
- Removed promotional Hebrew ("פורץ דרך", "ברמה הגבוהה ביותר", "באופן דרמטי")
- Removed copula avoidance ("מהווה", "משמש כ", "מתפקד כ") - replaced with `הוא`/`הם`
- Removed rule-of-three ("ייעול…שיפור…קידום", "הזיות, הטיות, ואחריותיות")
- Removed negative parallelism ("זה לא רק על X, זה על Y")
- Removed knowledge-cutoff disclaimer ("נכון לתאריך הזה, על פי המידע הזמין")
- Removed excessive hedging ("ניתן לטעון שייתכן שאולי…")
- Removed filler ("בעידן הדיגיטלי של היום", "בעולם המודרני המשתנה")
- Removed generic positive conclusion ("העתיד נראה ורוד, ימים מרגשים…")
- Replaced formal register ("על מנת" → drop; "במידה ו" → "אם")
- Removed slash-gender abuse (`משתמש/ת`)
- Added a real opinion + first person ("אני מודה ש…")

---

## Reference

This skill builds on:
- Wikipedia's "Signs of AI writing" patterns (https://en.wikipedia.org/wiki/Wikipedia:Signs_of_AI_writing)
- The MIT-licensed `humanizer` skill by Siqi Chen (https://github.com/blader/humanizer)

Key insight: AI Hebrew tends to default to (a) higher formal register and (b) translation-style patterns from English. Modern Israeli web Hebrew is conversational, direct, and tolerates English brand/tech terms in their original Latin script. The single most reliable Hebrew AI tell is `מהווה` - when in doubt, swap it for `הוא`/`היא`.
