!function(t, i) {
    "use strict";
    var s = "clingify"
      , e = "plugin_" + s
      , o = {
        breakpointHeight: 0,
        breakpointWidth: 0,
        throttle: 50,
        extraClass: "",
        wrapperClass: "js-clingify-wrapper",
        lockedClass: "js-clingify-locked",
        overrideClass: "js-clingify-permalock",
        placeholderClass: "js-clingify-placeholder",
        detached: t.noop,
        locked: t.noop,
        resized: t.noop,
        scrollingElem: "window",
        fixed: !0
    }
      , n = (t(i),
    function(i) {
        this.element = i,
        this.options = t.extend({}, o)
    }
    );
    n.prototype = {
        init: function(s) {
            t.extend(this.options, s);
            t(this.element),
            this.options.throttle;
            this.wrap(),
            "" !== this.options.extraClass && "string" == typeof this.options.extraClass && (this.findWrapper().addClass(this.options.extraClass),
            this.findPlaceholder().addClass(this.options.extraClass),
            this.options.wrapperClass += "." + this.options.extraClass,
            this.options.placeholderClass += "." + this.options.extraClass),
            this.options.scrollingElem = t("window" === this.options.scrollingElem ? i : this.options.scrollingElem),
            this.bindScroll(),
            this.bindResize()
        },
        bindResize: function() {
            var s, e = this;
            t(i).on("resize.Clingify", function(t) {
                s || (s = setTimeout(function() {
                    "resize" === t.type && "function" == typeof e.options.resized && e.options.resized(),
                    e.checkElemStatus(),
                    s = null
                }, e.options.throttle))
            })
        },
        bindScroll: function() {
            var i, s = this;
            t(s.options.scrollingElem).on("scroll.Clingify", function() {
                i || (i = setTimeout(function() {
                    s.checkElemStatus(),
                    i = null
                }, s.options.throttle))
            })
        },
        unbindResize: function() {
            t(i).off("resize.Clingify")
        },
        unbindScroll: function() {
            t(this.options.scrollingElem).off("scroll.Clingify")
        },
        destroy: function() {
            this.unwrap(),
            this.element.removeData(e)
        },
        checkCoords: function() {
            var i = {
                windowHeight: t(this.options.scrollingElem).height(),
                windowWidth: t(this.options.scrollingElem).width(),
                windowOffset: t(this.options.scrollingElem).scrollTop(),
                placeholderOffset: this.findPlaceholder().offset().top
            };
            return i
        },
        detachElem: function() {
            "function" == typeof this.options.detached && this.options.detached(),
            this.findWrapper().hasClass(this.options.overrideClass) || this.findWrapper().removeClass(this.options.lockedClass)
        },
        lockElem: function() {
            "function" == typeof this.options.locked && this.options.locked(),
            this.findWrapper().addClass(this.options.lockedClass)
        },
        findPlaceholder: function() {
            return this.$element.closest("." + this.options.placeholderClass)
        },
        findWrapper: function() {
            return this.$element.closest("." + this.options.wrapperClass)
        },
        checkElemStatus: function() {
            var t = this
              , i = this.checkCoords()
              , s = this.options.fixed
              , e = function() {
                return i.windowOffset >= i.placeholderOffset ? !0 : !1
            }
              , o = function() {
                return i.windowWidth >= t.options.breakpointWidth && i.windowHeight >= t.options.breakpointHeight ? !0 : !1
            }
              , n = function() {
                return t.options.scrollingElem.scrollTop()
            };
            e() && o() && s ? this.lockElem() : e() && o() || !s ? e() && o() && !s ? this.transformElem(n()) : e() && o() || s || this.untransformElem() : this.detachElem()
        },
        unwrap: function() {
            this.findPlaceholder().replaceWith(this.element)
        },
        test: function() {
            console.log("Public test method is working!")
        },
        transformElem: function(t) {
            var i = this.findWrapper()
              , s = t
              , e = "translateY(" + s + "px)";
            i.css({
                transform: e
            })
        },
        untransformElem: function() {
            var t = "translateY(0)";
            this.findWrapper().css({
                transform: t
            })
        },
        wrap: function() {
            var i = t("<div>").addClass(this.options.placeholderClass)
              , s = t("<div>").addClass(this.options.wrapperClass);
            this.$element = t(this.element),
            this.elemHeight = this.$element.outerHeight(),
            this.$element.wrap(i.height(this.elemHeight)).wrap(s),
            this.findPlaceholder().height(this.elemHeight)
        }
		
    },
    t.fn[s] = function(i) {
        var o, l;
        if (this.data(e)instanceof n || this.data(e, new n(this)),
        l = this.data(e),
        l.element = this,
        "undefined" == typeof i || "object" == typeof i)
            "function" == typeof l.init && l.init(i);
        else {
            if ("string" == typeof i && "function" == typeof l[i])
                return o = Array.prototype.slice.call(arguments, 1),
                l[i].apply(l, o);
            t.error("Method " + i + " does not exist on jQuery." + s)
        }
    }
}(jQuery, window, document);
