-- Class detail page: a tutor can paste an invite link to the follow-up
-- class (the "part 2" of the same topic) so enrolled students can jump
-- straight into booking it. Additive only — safe to run on live first.
USE skillswap;

ALTER TABLE TutorAvailability
    ADD COLUMN follow_up_link VARCHAR(255) NULL AFTER share_token;
