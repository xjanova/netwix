/** Shared by both players: stop playback and ask the viewer to choose registration. */
export function guestSignup() {
    return {
        signupOpen: false,
        signupUrl: '',

        gateGuestEpisode(ep) {
            if (!ep?.signupUrl) return false;
            this.stopPoll();
            this.dismissNext?.();
            this.resume();
            const video = this.$refs.video;
            if (video) {
                video._nxLoadId = (video._nxLoadId || 0) + 1;
                video.pause();
                video._nxHls?.destroy();
                video._nxHls = null;
                video.removeAttribute('src');
                video.load();
            }
            this._url = '';
            this.embedUrl = null;
            this.ytSrc = '';
            this.finished = false;
            this.playing = false;
            this.preparing = false;
            this.buffering = false;
            this.epMenu = false;
            this.signupUrl = ep.signupUrl;
            this.signupOpen = true;
            this.$nextTick(() => {
                if (!this.$refs.signupDialog.open) this.$refs.signupDialog.showModal();
            });
            return true;
        },

        closeSignup() {
            this.$refs.signupDialog.close();
            this.signupOpen = false;
            this.epMenu = true;
        },
    };
}
