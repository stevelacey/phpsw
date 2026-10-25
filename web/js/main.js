(function() {
  var phpsw;

  phpsw = {
    init: function() {
      this.modules();
      this.pjax();
      return this.nprogress();
    },
    modules: function() {
      this.email.init();
      this.events.init();
      this.hero.init();
      this.photos.init();
      return this.talks.init();
    },
    pjax: function() {
      return $(document).pjax('a:not([href*="images"])', '.content', {
        fragment: '.content',
        timeout: 10000
      }).on('pjax:success', (function(_this) {
        return function() {
          $('.navbar-toggle').addClass('collapsed');
          return _this.modules();
        };
      })(this));
    },
    nprogress: function() {
      NProgress.configure({
        ease: 'ease',
        minimum: .75,
        showSpinner: false,
        speed: 500
      });
      return $(document).on("pjax:start", function() {
        return NProgress.start();
      }).on("pjax:complete", function() {
        return NProgress.done();
      }).on("pjax:end", function() {
        return NProgress.remove();
      });
    }
  };

  $(document).on('click', 'a[data-toggle]', function(e) {
    return e.preventDefault();
  });

  phpsw.email = {
    init: function() {
      return $(".email").filter(':not(.dehumanized)').each((function(_this) {
        return function(i, el) {
          return $(el).addClass('dehumanized').attr("href", _this.dehumanize($(el).attr("href"))).html(_this.dehumanize($(el).html()));
        };
      })(this));
    },
    dehumanize: function(string) {
      return string.replace(/\s?at\s?/, "@").replace(/\s?dot\s?/g, ".").replace(/\%20/g, '').replace(/>\s+</g, "><");
    }
  };

  phpsw.events = {
    init: function() {
      this.el = $(".events");
      if (!this.el.length) {
        return;
      }
      this.calendar = this.el.find(".events-calendar");
      return this.datepicker.init(this);
    },
    datepicker: {
      init: function(e) {
        e.el.find(".event").each((function(_this) {
          return function(i, el) {
            var date;
            date = new Date($(el).find("time").attr("datetime"));
            return _this.events[_this.format(date)] = $(el);
          };
        })(this));
        return e.calendar.before($("<h2>", {
          text: "Calendar"
        })).datepicker({
          beforeShowDay: (function(_this) {
            return function(date) {
              var $a, $event;
              $event = _this.events[_this.format(date)];
              if ($event && $event.length) {
                $a = $event.find('h3 a');
                return {
                  classes: "events-calendar__event",
                  tooltip: $a.text()
                };
              } else {
                return {
                  enabled: false
                };
              }
            };
          })(this),
          todayHighlight: true
        }).on('changeDate', (function(_this) {
          return function(e) {
            if (e.date) {
              return _this.events[_this.format(e.date)].find('a').click();
            }
          };
        })(this));
      },
      events: {},
      format: function(d) {
        return d.getFullYear() + this.z(d.getMonth() + 1) + this.z(d.getDate());
      },
      z: function(x) {
        return ("0" + x).slice(-2);
      }
    }
  };

  phpsw.hero = {
    init: function() {
      this.el = $(".hero");
      this.present = !!this.el.length;
      this.absent = !this.present;
      if (Modernizr.touch || this.absent) {
        return;
      }
      this.image = this.el.find(".hero__image");
      this.overlay = this.el.find(".hero__overlay");
      if (!this.subscribed) {
        $(window).on("resize scroll", (function(_this) {
          return function() {
            var y;
            if (_this.present) {
              y = $(window).scrollTop();
              if (!(y < 0 || y > _this.el.offset().top + _this.el.height())) {
                return _this.animate(y);
              }
            }
          };
        })(this));
        return this.subscribed = true;
      }
    },
    animate: function(y) {
      this.image.css({
        transform: "translateY(" + (Math.round(y / 10)) + "px)"
      });
      return this.overlay.css({
        background: "rgba(0, 0, 0, " + (Math.round(y / 20) / 100) + ")"
      });
    },
    subscribed: false
  };

  phpsw.photos = {
    init: function() {
      this.photos = $(".photos");
      this.list = this.photos.find(".list--photos");
      this.hoot();
      this.fancy();
      if (this.list.length) {
        return this.on();
      } else {
        return this.off();
      }
    },
    on: function() {
      this.photos.on("mouseenter", this.x.mouseenter);
      this.photos.on("mouseleave", this.x.mouseleave);
      return $(window).on("resize", this.x.resize);
    },
    off: function() {
      this.photos.off("mouseenter", this.x.mouseenter);
      this.photos.off("mouseleave", this.x.mouseleave);
      return $(window).off("resize", this.x.resize);
    },
    config: {
      singleItem: true
    },
    delay: function(ms, func) {
      return setTimeout(func, ms);
    },
    hoot: function() {
      var count, i, items, _i, _ref;
      if (this.list.length) {
        items = this.list.find("li");
        count = Math.floor(this.list.innerWidth() / items.outerWidth(true)) * 2;
        this.owl = this.list.data("owlCarousel");
        if (count !== this.list.find(".owl-panel:first li").length) {
          this.list.html(items);
          for (i = _i = 0, _ref = items.length; count > 0 ? _i <= _ref : _i >= _ref; i = _i += count) {
            items.slice(i, i + count).wrapAll('<div class="owl-panel"></div>');
          }
          if (!this.owl) {
            return this.list.owlCarousel(this.config) && this.hoot();
          } else {
            return this.owl.reinit(this.config);
          }
        }
      }
    },
    mouseenter: function() {
      return $(window).on("scroll", this.x.scroll);
    },
    mouseleave: function() {
      return $(window).off("scroll", this.x.scroll);
    },
    scroll: function() {
      var x;
      x = $(document).scrollLeft();
      if (x !== 0) {
        if (x > 0) {
          this.owl.next();
        } else if (x < 0) {
          this.owl.prev();
        }
        $(window).off("scroll", this.x.scroll);
        return this.delay(500, (function(_this) {
          return function() {
            return $(window).off("scroll", _this.x.scroll).on("scroll", _this.x.scroll);
          };
        })(this));
      }
    },
    fancy: function() {
      return $(".fancybox").fancybox();
    },
    x: {
      resize: function() {
        return phpsw.photos.hoot();
      },
      mouseenter: function() {
        return phpsw.photos.mouseenter();
      },
      mouseleave: function() {
        return phpsw.photos.mouseleave();
      },
      scroll: function() {
        return phpsw.photos.scroll();
      }
    }
  };

  phpsw.talks = {
    init: function() {
      this.el = $(".talks");
      if (!this.el.length) {
        return;
      }
      this.el.find('[data-toggle="slides"]').each(function() {
        var $talk, $talk__details, $talk__slides;
        $talk = $(this).closest(".talk");
        $talk__details = $talk.find(".talk__details");
        $talk__slides = $($(this).attr("data-target"));
        $(this).data("details", $talk__details);
        $(this).data("slides", $talk__slides);
        return $talk__slides.detach().find("iframe").each(function() {
          return $(this).get(0).stop();
        });
      });
      return this.el.find('[data-toggle="slides"]').click(function() {
        var $talk__details, $talk__slides;
        $talk__details = $(this).data("details");
        $talk__slides = $(this).data("slides");
        if (!$talk__slides.parent().length) {
          $talk__slides.insertAfter($talk__details);
        }
        return $talk__slides.toggleClass("in");
      });
    }
  };

  $(function() {
    return phpsw.init();
  });

}).call(this);
