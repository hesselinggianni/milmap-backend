<?php

if (!function_exists('onavan_mail_template')) {
    function onavan_mail_template($title, $content, $logo = 'https://cdn.kyano.app/img/kyano-logo-dark.svg')
    {
        $mail = '<!doctype html>
        <html>
          <head>
            <meta name="viewport" content="width=device-width" />
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
            <title>'.$title.'</title>
          </head>
          <body>
            <style>
           
           @font-face {
                /* regular */
                font-family: "Gilroy";
                src: url("https://cdn.kyano.app/font/gilroy/Gilroy-Medium.ttf") format("TrueType");
                font-weight: 400;
                font-style: normal;
                display: swap;
              }
              
              @font-face {
                /* regular */
                font-family: "Gilroy";
                src: url("https://cdn.kyano.app/font/gilroy/Gilroy-SemiBold.ttf") format("TrueType");
                font-weight: 500;
                font-style: bold;
                display: swap;
              }
              
        
        img {
          border: none;
          -ms-interpolation-mode: bicubic;
          max-width: 100%; 
        }
        *{
            box-sizing: border-box;
        }
        
        body {
          background-color: #ffffff;
          font-family: "Gilroy";
          -webkit-font-smoothing: antialiased;
          font-size: 14px;
          line-height: 1.4;
          margin: 0;
          padding: 0;
          -ms-text-size-adjust: 100%;
          -webkit-text-size-adjust: 100%; 
  
          width: 100%; 
          box-sizing: border-box;
        }
        
      
          table td {
            font-family: "Gilroy";
            font-size: 14px;
            vertical-align: top; 
        }
        
        /* -------------------------------------
            BODY & CONTAINER
        ------------------------------------- */
      
        
        /* Set a max-width, and make it display as block so it will automatically stretch to that width, but will also shrink down on a phone or something */
        .container {
          display: block;
          margin: 0 auto;
          padding: 10px;
          max-width: 600px;
          box-sizing: border-box;
        }
        
     
        /* -------------------------------------
            HEADER, FOOTER, MAIN
        ------------------------------------- */
        .content {
            background: #fff;
            border-radius: 16px; 
            word-wrap: break-word;
            padding: 40px;
            border: 1px solid #eeeeee;
            box-sizing: border-box;
            mso-table-lspace: 0pt;
          mso-table-rspace: 0pt;
          }
        
        .header {
          padding: 20px 0;
        }
        

        .content-block {
          padding-bottom: 10px;
          padding-top: 10px;
        }
        
        .footer {
          margin-top: 10px;
          width: 100%; 
        }
          .footer td,
          .footer p,
          .footer span,
          .footer a {
            color: #9a9ea6;
            font-size: 12px;
            text-align: center; 
        }
        
        /* -------------------------------------
            TYPOGRAPHY
        ------------------------------------- */
        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
          color: #00163d;
          font-family: "Gilroy";
          font-weight: 700;
          line-height: 1.4;
          margin: 0;
          margin-bottom: 30px; 
        }
        
        h1 {
          font-size: 24px;
          font-weight: 700;
          text-transform: capitalize; 
        }
        
        p,
        ul,
        ol {
            font-family: "Gilroy";
          font-size: 16px;
          font-weight: normal;
          margin: 0;
          margin-bottom: 15px; 
        }
          p li,
          ul li,
          ol li {
            list-style-position: inside;
            margin-left: 5px; 
        }
        
        a {
          color: #24a1c7;
          text-decoration: underline; 
        }
        
        /* -------------------------------------
            BUTTONS
        ------------------------------------- */
        .btn {
          box-sizing: border-box;
          width: 100%; }
          .btn > tbody > tr > td {
            padding-bottom: 15px; }
          .btn table {
            min-width: auto;
            width: auto; 
        }
          .btn table td {
            background-color: #ffffff;
            border-radius: 4px;
            text-align: center; 
        
        }
          .btn a {
            background-color: #ffffff;
            border: solid 1px #24a1c7;
            border-radius: 4px;
            box-sizing: border-box;
            color: #24a1c7;
            cursor: pointer;
            display: inline-block;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin: 0;
            padding: 12px 25px;
            text-decoration: none;
            text-transform: capitalize; 
        }
        
        .btn-primary table td {
          background-color: #24a1c7; 
        }
        
        .btn-primary a {
          background-color: #24a1c7;
          border-color: #24a1c7;
          color: #ffffff; 
        }
        
        /* -------------------------------------
            OTHER STYLES THAT MIGHT BE USEFUL
        ------------------------------------- */
        .last {
          margin-bottom: 0; 
        }
        
        .first {
          margin-top: 0; 
        }
        
        .align-center {
          text-align: center; 
        }
        
        .align-right {
          text-align: right; 
        }
        
        .align-left {
          text-align: left; 
        }
        
        .clear {
          clear: both; 
        }
        
        .mt0 {
          margin-top: 0; 
        }
        
        .mb0 {
          margin-bottom: 0; 
        }
        
        
        
        .powered-by a {
          text-decoration: none; 
        }
        
        hr {
          border: 0;
          border-bottom: 1px solid #f6f6f6;
          Margin: 20px 0; 
        }
        
        @media screen and (max-width: 600px) {
          .container {
            width: 100%; 
          }
        
          .content {
            padding: 20px; 
          }
            
        }
     
        /* -------------------------------------
            PRESERVE THESE STYLES IN THE HEAD
        ------------------------------------- */
        @media all {
        
          .apple-link a {
            color: inherit !important;
            font-family: inherit !important;
            font-size: inherit !important;
            font-weight: inherit !important;
            line-height: inherit !important;
            text-decoration: none !important; 
          }
        
        }
            
            </style>
            <div class="container">
                <div class="header" style="text-align: center;">
                    <a href="https://kyano.app"><img src="'.$logo.'" height="40px" alt="Kyano apps logo"></a>
                  </div>
                    <div class="content">
                       '.$content.'
                    </div>
                    <div class="footer" style="text-align: center;">
                        <p class="content-block powered-by">
                            © '.date('Y').' '.config('app.company_name').' - Groningen, NL - All rights reserved.
                        </p>
                    </div>    
            </div>
          </body>
        </html>';
        return $mail;
    }
}


if (!function_exists('onavan_mail_template_simple')) {
  function onavan_mail_template_simple($title, $content)
  {
      $mail = '<!doctype html>
      <html>
        <head>
          <meta name="viewport" content="width=device-width" />
          <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
          <title>'.$title.'</title>
         
        </head>
        <body>
          <style>
         
         @font-face {
              /* regular */
              font-family: "Gilroy";
              src: url("https://cdn.kyano.app/font/gilroy/Gilroy-Medium.ttf") format("TrueType");
              font-weight: 400;
              font-style: normal;
              display: swap;
            }
            
            @font-face {
              /* regular */
              font-family: "Gilroy";
              src: url("https://cdn.kyano.app/font/gilroy/Gilroy-SemiBold.ttf") format("TrueType");
              font-weight: 500;
              font-style: bold;
              display: swap;
            }
            
      
      img {
        border: none;
        -ms-interpolation-mode: bicubic;
        max-width: 100%; 
      }
      *{
          box-sizing: border-box;
      }
      
      body {
        background-color: #ffffff;
        font-family: "Gilroy";
        -webkit-font-smoothing: antialiased;
        font-size: 14px;
        line-height: 1.4;
        margin: 0;
        padding: 0;
        -ms-text-size-adjust: 100%;
        -webkit-text-size-adjust: 100%; 

        width: 100%; 
        box-sizing: border-box;
      }
      
    
        table td {
          font-family: "Gilroy";
          font-size: 14px;
          vertical-align: top; 
      }
      
      /* -------------------------------------
          BODY & CONTAINER
      ------------------------------------- */
    
      
      /* Set a max-width, and make it display as block so it will automatically stretch to that width, but will also shrink down on a phone or something */
      .container {
        display: block;      
        padding: 10px;
        max-width: 800px;
        box-sizing: border-box;
      }
      
   
      /* -------------------------------------
        FOOTER, MAIN
      ------------------------------------- */
      .content {
          word-wrap: break-word;
          box-sizing: border-box;
          mso-table-lspace: 0pt;
        mso-table-rspace: 0pt;
        padding: 20px;
        }
      


      
      .content-block {
        padding-bottom: 10px;
        padding-top: 10px;
      }
      
      .footer {
        margin-top: 10px;
        width: 100%; 
      }
        .footer td,
        .footer p,
        .footer span,
        .footer a {
          color: #9a9ea6;
          font-size: 12px;
          text-align: left; 
      }
      
      /* -------------------------------------
          TYPOGRAPHY
      ------------------------------------- */
      h1,
      h2,
      h3,
      h4,
      h5,
      h6 {
        color: #00163d;
        font-family: "Gilroy";
        font-weight: 700;
        line-height: 1.4;
        margin: 0;
        margin-bottom: 30px; 
      }
      
      h1 {
        font-size: 24px;
        font-weight: 700;
        text-transform: capitalize; 
      }
      
      p,
      ul,
      ol {
          font-family: "Gilroy";
        font-size: 14px;
        font-weight: normal;
        margin: 0;
        margin-bottom: 10px; 
      }
        p li,
        ul li,
        ol li {
          list-style-position: inside;
          margin-left: 5px; 
      }
      
      a {
        color: #24a1c7;
        text-decoration: underline; 
      }
      
  
      /* -------------------------------------
          OTHER STYLES THAT MIGHT BE USEFUL
      ------------------------------------- */
      .last {
        margin-bottom: 0; 
      }
      
      .first {
        margin-top: 0; 
      }
      
      .align-center {
        text-align: center; 
      }
      
      .align-right {
        text-align: right; 
      }
      
      .align-left {
        text-align: left; 
      }
      
      .clear {
        clear: both; 
      }
      
      .mt0 {
        margin-top: 0; 
      }
      
      .mb0 {
        margin-bottom: 0; 
      }
      
   
      
      hr {
        border: 0;
        border-bottom: 1px solid #f6f6f6;
        Margin: 20px 0; 
      }
      
      @media screen and (max-width: 600px) {
        .container {
          width: 100%; 
        }                
      }
   
      /* -------------------------------------
          PRESERVE THESE STYLES IN THE HEAD
      ------------------------------------- */
      @media all {
      
        .apple-link a {
          color: inherit !important;
          font-family: inherit !important;
          font-size: inherit !important;
          font-weight: inherit !important;
          line-height: inherit !important;
          text-decoration: none !important; 
        }
      
      }
          
          </style>
 
          <div class="container">
            <div class="content">
                '.$content.'
            </div>

     
          </div>
 
        </body>
      </html>';
      return $mail;
  }
}
