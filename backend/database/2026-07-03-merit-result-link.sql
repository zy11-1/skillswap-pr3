-- Merit applications carry a link to the tutor's academic result / transcript
-- so the admin can view it before forwarding the application to the UTM merit
-- coordinator. Additive only — safe to run on live first.
USE skillswap;

ALTER TABLE MeritRequest
    ADD COLUMN result_link VARCHAR(255) NULL AFTER review_count;
