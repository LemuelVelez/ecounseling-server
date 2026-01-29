<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="utf-8">
        <title>Verify your E-Guidance Appointment System email</title>
    </head>

    <body
        style="margin:0; padding:20px; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" role="presentation"
                        style="background-color:#ffffff; border-radius:8px; padding:24px;">
                        <tr>
                            <td>
                                <h1 style="font-size:20px; margin-bottom:16px; color:#92400e;">
                                    Verify your E-Guidance Appointment System email
                                </h1>

                                <p style="font-size:14px; color:#374151; margin-bottom:12px;">
                                    Hello {{ $user->name ?? 'Student' }},
                                </p>

                                <p style="font-size:14px; color:#374151; margin-bottom:16px;">
                                    Thank you for registering for the JRMSU E-Guidance Appointment System.
                                    Please confirm that this is your email address by clicking the button below.
                                </p>

                                <p style="text-align:center; margin: 24px 0;">
                                    <a href="{{ $verificationUrl }}"
                                        style="display:inline-block; padding:10px 18px; border-radius:9999px; background-color:#f59e0b; color:#111827; text-decoration:none; font-weight:600;">
                                        Verify email address
                                    </a>
                                </p>

                                <p style="font-size:12px; color:#6b7280; margin-bottom:8px;">
                                    If the button doesn&apos;t work, copy and paste this link into your browser:
                                </p>

                                <p style="font-size:12px; color:#2563eb; word-break:break-all;">
                                    {{ $verificationUrl }}
                                </p>

                                <p style="font-size:12px; color:#6b7280; margin-top:24px;">
                                    If you did not create an account, you can safely ignore this email.
                                </p>

                                <p style="font-size:12px; color:#6b7280;">
                                    &mdash; JRMSU E-Guidance Appointment System
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>

</html>