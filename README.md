#Indent skip patterns

This E.M. provides a quick and easy way to indent questions.  The practical use is to indent questions that have skip patterns.

It uses a single action tag: @SKIP-LEFT followed by a number between 1 and 9.

Examples:
@SPACE-LEFT=1
@SPACE-LEFT=3
@SPACE-LEFT=4
@SPACE-LEFT=6

Each project can set the base size of the indent. One project may wish to indent questions by 20 pixels.  Another may wish to
indent by 40 pixels.

Example:
If a project sets the base indent space to 20 then the following indentations will happen:

@SPACE-LEFT=1 Indented: 20px
@SPACE-LEFT=3 Indented: 60px
@SPACE-LEFT=4 Indented: 80px
@SPACE-LEFT=6 Indented: 120px

If a project does not provide a base pixel width than 30 pixels will be set as the base.

This module can be used to indent questions even if they do not use a skip pattern.
It will not automatically indent questions that have skip logic.