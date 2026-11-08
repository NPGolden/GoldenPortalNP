<?php /** R89-001: streams a candidate's CV to HR / the hiring manager. ?p=rc_cv&id= */ require_staff(); rcr_cv_stream((int)($_GET['id'] ?? 0));
