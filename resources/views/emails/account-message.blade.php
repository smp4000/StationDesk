<!doctype html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $mailTitle ?? 'E-Mail-Adresse bestätigen' }} · StationDeck</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4ef;color:#153c3d;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;font-size:1px;color:#f3f4ef;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $preheader ?? 'Bestätige deine E-Mail-Adresse und starte mit deiner ersten Tankstelle.' }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4ef;">
        <tr><td align="center" style="padding:36px 16px;">
            <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
                <tr><td style="padding:0 8px 24px;font-size:26px;line-height:32px;font-weight:bold;letter-spacing:-1px;">Station<span style="color:#28786a;">Deck</span></td></tr>
                <tr><td style="background-color:#ffffff;border:1px solid #dde5df;border-radius:16px;overflow:hidden;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr><td style="padding:30px 28px;background-color:#153c3d;border-radius:15px 15px 0 0;">
                            <p style="margin:0 0 14px;color:#b8d8ce;font-size:11px;line-height:16px;letter-spacing:2px;font-weight:bold;">{{ $eyebrow ?? 'DEIN START MIT STATIONDECK' }}</p>
                            <h1 style="margin:0;color:#ffffff;font-size:30px;line-height:38px;font-weight:bold;">{{ $heading ?? 'Nur noch ein Klick.' }}</h1>
                            <p style="margin:12px 0 0;color:#d5e7e0;font-size:16px;line-height:25px;">{{ $subtitle ?? 'Deine erste Tankstelle wartet auf dich.' }}</p>
                        </td></tr>
                        <tr><td style="padding:30px 28px;">
                            <p style="margin:0 0 16px;font-size:17px;line-height:26px;font-weight:bold;">Hallo {{ $firstName }},</p>
                            <p style="margin:0 0 24px;color:#4c6260;font-size:16px;line-height:26px;">{{ $introduction ?? 'schön, dass du dabei bist. Bestätige bitte deine E-Mail-Adresse, damit wir deine Tankstelle und deinen Zugang als Chef einrichten können.' }}</p>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr><td align="center" bgcolor="#17685b" style="border-radius:8px;mso-padding-alt:16px 24px;">
                                    <a href="{{ $url }}" style="display:inline-block;padding:16px 24px;border:1px solid #17685b;border-radius:8px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:22px;font-weight:bold;color:#ffffff;text-decoration:none;mso-padding-alt:0;">{{ $buttonLabel ?? 'E-Mail-Adresse bestätigen' }}</a>
                                </td></tr>
                            </table>
                            <p style="margin:14px 0 28px;color:#60716d;font-size:13px;line-height:21px;">Der Link ist {{ $minutes }} Minuten gültig. {{ $linkHint ?? 'Öffne ihn im Browser, in dem du bei StationDeck angemeldet bist.' }}</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef3eb;border-radius:10px;">
                                <tr><td style="padding:20px;">
                                    <h2 style="margin:0 0 10px;font-size:16px;line-height:24px;">{{ $nextHeading ?? 'So geht es danach weiter' }}</h2>
                                    <p style="margin:0;color:#4c6260;font-size:14px;line-height:24px;">{{ $nextText ?? 'Wir richten deine erste Tankstelle und deinen Chef-Zugang ein. Sobald alles bereitsteht, beginnt dein 30-Tage-Testzeitraum.' }}</p>
                                </td></tr>
                            </table>
                            <p style="margin:26px 0 8px;color:#60716d;font-size:12px;line-height:19px;">Der Button funktioniert nicht? Kopiere diesen Link in deinen Browser:</p>
                            <p style="margin:0;font-size:12px;line-height:19px;word-break:break-all;overflow-wrap:anywhere;"><a href="{{ $url }}" style="color:#17685b;text-decoration:underline;word-break:break-all;">{{ $url }}</a></p>
                        </td></tr>
                    </table>
                </td></tr>
                <tr><td style="padding:22px 8px 0;color:#687773;font-size:12px;line-height:20px;">
                    <p style="margin:0 0 10px;">{{ $footerNote ?? 'Du hast dich nicht registriert? Dann kannst du diese E-Mail ignorieren.' }}</p>
                    <p style="margin:0;font-weight:bold;color:#365b53;">StationDeck · Gemeinsam den Betrieb im Blick.</p>
                </td></tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td></tr>
    </table>
</body>
</html>
