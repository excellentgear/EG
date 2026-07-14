/*!
 * FullCalendar v1.6.1
 * Docs & License: http://arshaw.com/fullcalendar/
 * (c) 2013 Adam Shaw
 */

/*
 * Use fullcalendar.css for basic styling.
 * For event drag & drop, requires jQuery UI draggable.
 * For event resizing, requires jQuery UI resizable.
 */

(function ($, undefined) {
	var defaults = {
		// display
		defaultView: 'month',
		aspectRatio: 1.35,
		header: {
			left: '',
			center: ''
		},
		weekends: true,
	
		// time formats
		titleFormat: {
			month: '',
			week: "",
			day: ''
		},
		columnFormat: {
			month: 'ddd',
			week: '',
			day: ''
		},
		timeFormat: { // for event elements
			'': '' // default
		},
		// locale
		isRTL: false,
		dayNamesShort: ['時段', '一', '二', '三', '四', '五', '備註'],
		buttonText: {
		},
		// jquery-ui theming
		theme: false,
		buttonIcons: {
		},

	};

	;;

	var fc = $.fullCalendar = { version: "1.6.1" };
	var fcViews = fc.views = {};

	$.fn.fullCalendar = function (options) {

		// method calling
		if (typeof options == 'string') {
			var args = Array.prototype.slice.call(arguments, 1);
			var res;
			this.each(function () {

			});
			if (res !== undefined) {
				return res;
			}
			return this;
		}

		// would like to have this logic in EventManager, but needs to happen before options are recursively extended
		var eventSources = options.eventSources || [];
		delete options.eventSources;
		if (options.events) {
			eventSources.push(options.events);
			delete options.events;
		}

		options = $.extend(true, {},
			defaults,
			(options.isRTL || options.isRTL === undefined && defaults.isRTL) ? rtlDefaults : {},
			options
		);

		this.each(function (i, _element) {
			var element = $(_element);
			var calendar = new Calendar(element, options, eventSources);
			element.data('fullCalendar', calendar); // TODO: look into memory leak implications
			calendar.render();
		});
		return this;
	};

	// function for adding/overriding defaults
	function setDefaults(d) {
		$.extend(true, defaults, d);
	}
	;;

	function Calendar(element, options, eventSources) {
		var t = this;

		// exports
		t.options = options;
		t.render = render;
		t.destroy = destroy;
		t.reportEvents = reportEvents;
		t.formatDate = function (format, date) { return formatDate(format, date, options) };
		// imports
		EventManager.call(t, options, eventSources);
		var isFetchNeeded = t.isFetchNeeded;
		var fetchEvents = t.fetchEvents;

		// locals
		var _element = element[0];
		var header;
		var headerElement;
		var content;
		var tm; // for making theme classes
		var currentView;
		var viewInstances = {};
		var elementOuterWidth;
		var suggestedViewHeight;
		var absoluteViewElement;
		var resizeUID = 0;
		var ignoreWindowResize = 0;
		var date = new Date();
		var events = [];
		var _dragElement;

		/* Main Rendering
		-----------------------------------------------------------------------------*/
		setYMD(date, options.year, options.month, options.date);

		function render(inc) {
			if (!content) {
				initialRender();
			} else {
				calcSize();
				markSizesDirty();
				markEventsDirty();
				renderView(inc);
			}
		}

		function initialRender() {
			tm = options.theme ? 'ui' : 'fc';
			element.addClass('fc');
			if (options.isRTL) {
				element.addClass('fc-rtl');
			}
			else {
				element.addClass('fc-ltr');
			}
			if (options.theme) {
				element.addClass('ui-widget');
			}
			content = $("<div class='fc-content' style='position:relative'/>")
				.prependTo(element);
			header = new Header(t, options);
			headerElement = header.render();
			if (headerElement) {
				element.prepend(headerElement);
			}
			changeView(options.defaultView);
			$(window).resize(windowResize);
			// needed for IE in a 0x0 iframe, b/c when it is resized, never triggers a windowResize
			if (!bodyVisible()) {
				lateRender();
			}
		}

		// called when we know the calendar couldn't be rendered when it was initialized,
		// but we think it's ready now
		function lateRender() {
			setTimeout(function () { // IE7 needs this so dimensions are calculated correctly
				if (!currentView.start && bodyVisible()) { // !currentView.start makes sure this never happens more than once
					renderView();
				}
			}, 0);
		}

		function elementVisible() {
			return _element.offsetWidth !== 0;
		}

		/* View Rendering
		-----------------------------------------------------------------------------*/
		// TODO: improve view switching (still weird transition in IE, and FF has whiteout problem)

		function changeView(newViewName) {
			if (!currentView || newViewName != currentView.name) {
				ignoreWindowResize++; // because setMinHeight might change the height before render (and subsequently setSize) is reached

				unselect();

				var oldView = currentView;
				var newViewElement;

				if (oldView) {
					(oldView.beforeHide || noop)(); // called before changing min-height. if called after, scroll state is reset (in Opera)
					setMinHeight(content, content.height());
					oldView.element.hide();
				} else {
					setMinHeight(content, 1); // needs to be 1 (not 0) for IE7, or else view dimensions miscalculated
				}
				content.css('overflow', 'hidden');

				currentView = viewInstances[newViewName];
				if (currentView) {
					currentView.element.show();
				} else {
					currentView = viewInstances[newViewName] = new fcViews[newViewName](
						newViewElement = absoluteViewElement =
						$("<div class='fc-view fc-view-" + newViewName + "' style='position:absolute'/>")
							.appendTo(content),
						t // the calendar object
					);
				}

				if (oldView) {
					header.deactivateButton(oldView.name);
				}
				header.activateButton(newViewName);

				renderView(); // after height has been set, will make absoluteViewElement's position=relative, then set to null

				content.css('overflow', '');
				if (oldView) {
					setMinHeight(content, 1);
				}

				if (!newViewElement) {
					(currentView.afterShow || noop)(); // called after setting min-height/overflow, so in final scroll state (for Opera)
				}

				ignoreWindowResize--;
			}
		}

		function renderView(inc) {
			if (elementVisible()) {
				ignoreWindowResize++; // because renderEvents might temporarily change the height before setSize is reached

				unselect();

				if (suggestedViewHeight === undefined) {
					calcSize();
				}

				var forceEventRender = false;
				if (!currentView.start || inc || date < currentView.start || date >= currentView.end) {
					// view must render an entire new date range (and refetch/render events)
					currentView.render(date, inc || 0); // responsible for clearing events
					setSize(true);
					forceEventRender = true;
				}
				else if (currentView.sizeDirty) {
					// view must resize (and rerender events)
					currentView.clearEvents();
					setSize();
					forceEventRender = true;
				}
				else if (currentView.eventsDirty) {
					currentView.clearEvents();
					forceEventRender = true;
				}
				currentView.sizeDirty = false;
				currentView.eventsDirty = false;
				updateEvents(forceEventRender);

				elementOuterWidth = element.outerWidth();

				ignoreWindowResize--;
				currentView.trigger('viewDisplay', _element);
			}
		}

		function calcSize() {
			if (options.contentHeight) {
				suggestedViewHeight = options.contentHeight;
			}
			else if (options.height) {
				suggestedViewHeight = options.height - (headerElement ? headerElement.height() : 0) - vsides(content);
			}
			else {
				suggestedViewHeight = Math.round(content.width() / Math.max(options.aspectRatio, .5));
			}
		}


		function setSize(dateChanged) { // todo: dateChanged?
			ignoreWindowResize++;
			currentView.setHeight(suggestedViewHeight, dateChanged);
			if (absoluteViewElement) {
				absoluteViewElement.css('position', 'relative');
				absoluteViewElement = null;
			}
			currentView.setWidth(content.width(), dateChanged);
			ignoreWindowResize--;
		}

		/* Event Fetching/Rendering
		-----------------------------------------------------------------------------*/

		// fetches events if necessary, rerenders events if necessary (or if forced)
		function updateEvents(forceRender) {
			if (!options.lazyFetching || isFetchNeeded(currentView.visStart, currentView.visEnd)) {
				refetchEvents();
			}
			else if (forceRender) {
				rerenderEvents();
			}
		}

		function refetchEvents() {
			fetchEvents(currentView.visStart, currentView.visEnd); // will call reportEvents
		}

		// called when event data arrives
		function reportEvents(_events) {
			events = _events;
			rerenderEvents();
		}

		// attempts to rerenderEvents
		function rerenderEvents(modifiedEventID) {
			markEventsDirty();
			if (elementVisible()) {
				currentView.clearEvents();
				currentView.renderEvents(events, modifiedEventID);
				currentView.eventsDirty = false;
			}
		}
	}
	;;

	function Header(calendar, options) {
		var t = this;

		// exports
		t.render = render;
		t.destroy = destroy;
		t.updateTitle = updateTitle;
		t.activateButton = activateButton;
		t.deactivateButton = deactivateButton;
		t.disableButton = disableButton;
		t.enableButton = enableButton;

		// locals
		var element = $([]);
		var tm;

		function render() {
			tm = options.theme ? 'ui' : 'fc';
			var sections = options.header;
			if (sections) {
				element = $("<table class='fc-header' style='width:900px'/>")//changewidth:100%
					.append(
						$("<tr/>")
							.append(renderSection('left'))
							.append(renderSection('center'))
							.append(renderSection('right'))
					);
				return element;
			}
		}

		function destroy() {
			element.remove();
		}

		function renderSection(position) {
			var e = $("<td class='fc-header-" + position + "'/>");
			var buttonStr = options.header[position];
			if (buttonStr) {
				$.each(buttonStr.split(' '), function (i) {
					if (i > 0) {
						e.append("<span class='fc-header-space'/>");
					}
					var prevButton;
					$.each(this.split(','), function (j, buttonName) {
						if (buttonName == 'title') {
							e.append("<span class='fc-header-title'><h2>&nbsp;</h2></span>");
							if (prevButton) {
								prevButton.addClass(tm + '-corner-right');
							}
							prevButton = null;
						} else {
							var buttonClick;
							if (calendar[buttonName]) {
								buttonClick = calendar[buttonName]; // calendar method
							}
							else if (fcViews[buttonName]) {
								buttonClick = function () {
									button.removeClass(tm + '-state-hover'); // forget why
									calendar.changeView(buttonName);
								};
							}
							if (buttonClick) {
								var icon = options.theme ? smartProperty(options.buttonIcons, buttonName) : null; // why are we using smartProperty here?
								var text = smartProperty(options.buttonText, buttonName); // why are we using smartProperty here?
								var button = $(
									"<span class='fc-button fc-button-" + buttonName + " " + tm + "-state-default'>" +
									(icon ?
										"<span class='fc-icon-wrap'>" +
										"<span class='ui-icon ui-icon-" + icon + "'/>" +
										"</span>" :
										text
									) +
									"</span>"
								)
									.click(function () {
										if (!button.hasClass(tm + '-state-disabled')) {
											buttonClick();
										}
									})
									.mousedown(function () {
										button
											.not('.' + tm + '-state-active')
											.not('.' + tm + '-state-disabled')
											.addClass(tm + '-state-down');
									})
									.mouseup(function () {
										button.removeClass(tm + '-state-down');
									})
									.hover(
										function () {
											button
												.not('.' + tm + '-state-active')
												.not('.' + tm + '-state-disabled')
												.addClass(tm + '-state-hover');
										},
										function () {
											button
												.removeClass(tm + '-state-hover')
												.removeClass(tm + '-state-down');
										}
									)
									.appendTo(e);
								disableTextSelection(button);
								if (!prevButton) {
									button.addClass(tm + '-corner-left');
								}
								prevButton = button;
							}
						}
					});
					if (prevButton) {
						prevButton.addClass(tm + '-corner-right');
					}
				});
			}
			return e;
		}

		function updateTitle(html) {
			element.find('h2')
				.html(html);
		}

		function activateButton(buttonName) {
			element.find('span.fc-button-' + buttonName)
				.addClass(tm + '-state-active');
		}

		function deactivateButton(buttonName) {
			element.find('span.fc-button-' + buttonName)
				.removeClass(tm + '-state-active');
		}

		function disableButton(buttonName) {
			element.find('span.fc-button-' + buttonName)
				.addClass(tm + '-state-disabled');
		}

		function enableButton(buttonName) {
			element.find('span.fc-button-' + buttonName)
				.removeClass(tm + '-state-disabled');
		}
	}

	;;

	fc.sourceNormalizers = [];
	fc.sourceFetchers = [];

	var ajaxDefaults = {
		dataType: 'json',
		cache: false
	};

	var eventGUID = 1;

	function EventManager(options, _sources) {
		var t = this;

		// exports
		t.isFetchNeeded = isFetchNeeded;
		t.fetchEvents = fetchEvents;
		t.addEventSource = addEventSource;
		t.removeEventSource = removeEventSource;
		t.updateEvent = updateEvent;
		t.renderEvent = renderEvent;
		t.removeEvents = removeEvents;
		t.clientEvents = clientEvents;
		t.normalizeEvent = normalizeEvent;

		// imports
		var trigger = t.trigger;
		var getView = t.getView;
		var reportEvents = t.reportEvents;

		// locals
		var stickySource = { events: [] };
		var sources = [stickySource];
		var rangeStart, rangeEnd;
		var currentFetchID = 0;
		var pendingSourceCnt = 0;
		var loadingLevel = 0;
		var cache = [];

		for (var i = 0; i < _sources.length; i++) {
			_addEventSource(_sources[i]);
		}

		/* Fetching
		-----------------------------------------------------------------------------*/

		function isFetchNeeded(start, end) {
			return !rangeStart || start < rangeStart || end > rangeEnd;
		}

		function fetchEvents(start, end) {
			rangeStart = start;
			rangeEnd = end;
			cache = [];
			var fetchID = ++currentFetchID;
			var len = sources.length;
			pendingSourceCnt = len;
			for (var i = 0; i < len; i++) {
				fetchEventSource(sources[i], fetchID);
			}
		}

		function fetchEventSource(source, fetchID) {
			_fetchEventSource(source, function (events) {
				if (fetchID == currentFetchID) {
					if (events) {

						if (options.eventDataTransform) {
							events = $.map(events, options.eventDataTransform);
						}
						if (source.eventDataTransform) {
							events = $.map(events, source.eventDataTransform);
						}
						// TODO: this technique is not ideal for static array event sources.
						//  For arrays, we'll want to process all events right in the beginning, then never again.

						for (var i = 0; i < events.length; i++) {
							events[i].source = source;
							normalizeEvent(events[i]);
						}
						cache = cache.concat(events);
					}
					pendingSourceCnt--;
					if (!pendingSourceCnt) {
						reportEvents(cache);
					}
				}
			});
		}

		function _fetchEventSource(source, callback) {
			var i;
			var fetchers = fc.sourceFetchers;
			var res;
			for (i = 0; i < fetchers.length; i++) {
				res = fetchers[i](source, rangeStart, rangeEnd, callback);
				if (res === true) {
					// the fetcher is in charge. made its own async request
					return;
				}
				else if (typeof res == 'object') {
					// the fetcher returned a new source. process it
					_fetchEventSource(res, callback);
					return;
				}
			}
			var events = source.events;
			if (events) {
				if ($.isFunction(events)) {
					pushLoading();
					events(cloneDate(rangeStart), cloneDate(rangeEnd), function (events) {
						callback(events);
						popLoading();
					});
				}
				else if ($.isArray(events)) {
					callback(events);
				}
				else {
					callback();
				}
			} else {
				var url = source.url;
				if (url) {
					var success = source.success;
					var error = source.error;
					var complete = source.complete;
					var data = $.extend({}, source.data || {});
					var startParam = firstDefined(source.startParam, options.startParam);
					var endParam = firstDefined(source.endParam, options.endParam);
					if (startParam) {
						data[startParam] = Math.round(+rangeStart / 1000);
					}
					if (endParam) {
						data[endParam] = Math.round(+rangeEnd / 1000);
					}
					pushLoading();
					$.ajax($.extend({}, ajaxDefaults, source, {
						data: data,
						success: function (events) {
							events = events || [];
							var res = applyAll(success, this, arguments);
							if ($.isArray(res)) {
								events = res;
							}
							callback(events);
						},
						error: function () {
							applyAll(error, this, arguments);
							callback();
						},
						complete: function () {
							applyAll(complete, this, arguments);
							popLoading();
						}
					}));
				} else {
					callback();
				}
			}
		}

		/* Sources
		-----------------------------------------------------------------------------*/
		function _addEventSource(source) {
			if ($.isFunction(source) || $.isArray(source)) {
				source = { events: source };
			}
			else if (typeof source == 'string') {
				source = { url: source };
			}
			if (typeof source == 'object') {
				normalizeSource(source);
				sources.push(source);
				return source;
			}
		}

		/* Event Normalization
		-----------------------------------------------------------------------------*/

		function normalizeEvent(event) {
			var source = event.source || {};
			var ignoreTimezone = firstDefined(source.ignoreTimezone, options.ignoreTimezone);
			event._id = event._id || (event.id === undefined ? '_fc' + eventGUID++ : event.id + '');
			if (event.date) {
				if (!event.start) {
					event.start = event.date;
				}
				delete event.date;
			}
			event._start = cloneDate(event.start = parseDate(event.start, ignoreTimezone));
			event.end = parseDate(event.end, ignoreTimezone);
			if (event.end && event.end <= event.start) {
				event.end = null;
			}
			event._end = event.end ? cloneDate(event.end) : null;
			if (event.allDay === undefined) {
				event.allDay = firstDefined(source.allDayDefault, options.allDayDefault);
			}
			if (event.className) {
				if (typeof event.className == 'string') {
					event.className = event.className.split(/\s+/);
				}
			} else {
				event.className = [];
			}
			// TODO: if there is no start date, return false to indicate an invalid event
		}
	}

	;;

	fc.addDays = addDays;
	fc.cloneDate = cloneDate;
	fc.parseDate = parseDate;
	fc.parseISO8601 = parseISO8601;
	fc.parseTime = parseTime;
	fc.formatDate = formatDate;
	fc.formatDates = formatDates;

	/* Date Math
	-----------------------------------------------------------------------------*/

	var dayIDs = ['日', '一', '二', '三', '四', '五', '六'],
		DAY_MS = 86400000,
		HOUR_MS = 3600000,
		MINUTE_MS = 60000;

	function addDays(d, n, keepTime) { // deals with daylight savings
		if (+d) {
			var dd = d.getDate() + n,
				check = cloneDate(d);
			check.setHours(9); // set to middle of day
			check.setDate(dd);
			d.setDate(dd);
			if (!keepTime) {
				clearTime(d);
			}
			fixDate(d, check);
		}
		return d;
	}

	function clearTime(d) {
		d.setHours(0);
		d.setMinutes(0);
		d.setSeconds(0);
		d.setMilliseconds(0);
		return d;
	}

	function cloneDate(d, dontKeepTime) {
		if (dontKeepTime) {
			return clearTime(new Date(+d));
		}
		return new Date(+d);
	}

	function parseDate(s, ignoreTimezone) { // ignoreTimezone defaults to true
		if (typeof s == 'object') { // already a Date object
			return s;
		}
		if (typeof s == 'number') { // a UNIX timestamp
			return new Date(s * 1000);
		}
		if (typeof s == 'string') {
			if (s.match(/^\d+(\.\d+)?$/)) { // a UNIX timestamp
				return new Date(parseFloat(s) * 1000);
			}
			if (ignoreTimezone === undefined) {
				ignoreTimezone = true;
			}
			return parseISO8601(s, ignoreTimezone) || (s ? new Date(s) : null);
		}
		// TODO: never return invalid dates (like from new Date(<string>)), return null instead
		return null;
	}

	/* Date Formatting
	-----------------------------------------------------------------------------*/
	// TODO: use same function formatDate(date, [date2], format, [options])

	//星期標頭
	function formatDate(date, format, options) {
		return formatDates(date, null, format, options);
	}

	function formatDates(date1, date2, format, options) {
		options = options || defaults;
		var date = date1,
			otherDate = date2,
			i, len = format.length, c,
			i2, formatter,
			res = '';
		for (i = 0; i < len; i++) {
			c = format.charAt(i);
			if (c == "'") {
				for (i2 = i + 1; i2 < len; i2++) {
					if (format.charAt(i2) == "'") {
						if (date) {
							if (i2 == i + 1) {
								res += "'";
							} else {
								res += format.substring(i + 1, i2);
							}
							i = i2;
						}
						break;
					}
				}
			}
			else if (c == '(') {
				for (i2 = i + 1; i2 < len; i2++) {
					if (format.charAt(i2) == ')') {
						var subres = formatDate(date, format.substring(i + 1, i2), options);
						if (parseInt(subres.replace(/\D/, ''), 10)) {
							res += subres;
						}
						i = i2;
						break;
					}
				}
			}
			else if (c == '[') {
				for (i2 = i + 1; i2 < len; i2++) {
					if (format.charAt(i2) == ']') {
						var subformat = format.substring(i + 1, i2);
						var subres = formatDate(date, subformat, options);
						if (subres != formatDate(otherDate, subformat, options)) {
							res += subres;
						}
						i = i2;
						break;
					}
				}
			}
			else if (c == '{') {
				date = date2;
				otherDate = date1;
			}
			else if (c == '}') {
				date = date1;
				otherDate = date2;
			}
			else {
				for (i2 = len; i2 > i; i2--) {
					if (formatter = dateFormatters[format.substring(i, i2)]) {
						if (date) {
							res += formatter(date, options);
						}
						i = i2 - 1;
						break;
					}
				}
				if (i2 == i) {
					if (date) {
						res += c;
					}
				}
			}
		}
		return res;
	};

	var dateFormatters = {

		d: function (d) { return d.getDate() },
		dd: function (d) { return zeroPad(d.getDate()) },
		ddd: function (d, o) { return o.dayNamesShort[d.getDay()] },
		dddd: function (d, o) { return o.dayNames[d.getDay()] },
		u: function (d) { return formatDate(d, "yyyy-MM-dd'T'HH:mm:ss'Z'") },
		S: function (d) {
			var date = d.getDate();
			if (date > 10 && date < 20) {
				return 'th';
			}
			return ['st', 'nd', 'rd'][date % 10 - 1] || 'th';
		},
		w: function (d, o) { // local
			return o.weekNumberCalculation(d);
		},
		W: function (d) { // ISO
			return iso8601Week(d);
		}
	};
	fc.dateFormatters = dateFormatters;

	/* thanks jQuery UI (https://github.com/jquery/jquery-ui/blob/master/ui/jquery.ui.datepicker.js)
	 * 
	 * Set as calculateWeek to determine the week of the year based on the ISO 8601 definition.
	 * @param  date  Date - the date to get the week for
	 * @return  number - the number of the week within the year that contains this date
	 */
	function iso8601Week(date) {
		var time;
		var checkDate = new Date(date.getTime());

		// Find Thursday of this week starting on Monday
		checkDate.setDate(checkDate.getDate() + 4 - (checkDate.getDay() || 7));

		time = checkDate.getTime();
		checkDate.setMonth(0); // Compare with Jan 1
		checkDate.setDate(1);
		return Math.floor(Math.round((time - checkDate) / 86400000) / 7) + 1;
	}

	fc.applyAll = applyAll;

	/* Event Date Math
	-----------------------------------------------------------------------------*/

	function exclEndDay(event) {
		if (event.end) {
			return _exclEndDay(event.end, event.allDay);
		} else {
			return addDays(cloneDate(event.start), 1);
		}
	}

	/* Event Sorting
	-----------------------------------------------------------------------------*/

	// event rendering utilities
	function sliceSegs(events, visEventEnds, start, end) {
		var segs = [],
			i, len = events.length, event,
			eventStart, eventEnd,
			segStart, segEnd,
			isStart, isEnd;
		for (i = 0; i < len; i++) {
			event = events[i];
			eventStart = event.start;
			eventEnd = visEventEnds[i];
			if (eventEnd > start && eventStart < end) {
				if (eventStart < start) {
					segStart = cloneDate(start);
					isStart = false;
				} else {
					segStart = eventStart;
					isStart = true;
				}
				if (eventEnd > end) {
					segEnd = cloneDate(end);
					isEnd = false;
				} else {
					segEnd = eventEnd;
					isEnd = true;
				}
				segs.push({
					event: event,
					start: segStart,
					end: segEnd,
					isStart: isStart,
					isEnd: isEnd,
					msLength: segEnd - segStart
				});
			}
		}
		return segs.sort(segCmp);
	}

	// event rendering calculation utilities
	function stackSegs(segs) {
		var levels = [],
			i, len = segs.length, seg,
			j, collide, k;
		for (i = 0; i < len; i++) {
			seg = segs[i];
			j = 0; // the level index where seg should belong
			while (true) {
				collide = false;
				if (levels[j]) {
					for (k = 0; k < levels[j].length; k++) {
						if (segsCollide(levels[j][k], seg)) {
							collide = true;
							break;
						}
					}
				}
				if (collide) {
					j++;
				} else {
					break;
				}
			}
			if (levels[j]) {
				levels[j].push(seg);
			} else {
				levels[j] = [seg];
			}
		}
		return levels;
	}

	//顯示行程格式
	function hsides(element, includeMargins) {
		return hpadding(element) + hborders(element) + (includeMargins ? hmargins(element) : 0);
	}

	function hpadding(element) {
		return (parseFloat($.css(element[0], 'paddingLeft', true)) || 0) +
			(parseFloat($.css(element[0], 'paddingRight', true)) || 0);
	}

	function hmargins(element) {
		return (parseFloat($.css(element[0], 'marginLeft', true)) || 0) +
			(parseFloat($.css(element[0], 'marginRight', true)) || 0);
	}

	function hborders(element) {
		return (parseFloat($.css(element[0], 'borderLeftWidth', true)) || 0) +
			(parseFloat($.css(element[0], 'borderRightWidth', true)) || 0);
	}

	function vsides(element, includeMargins) {
		return vpadding(element) + vborders(element) + (includeMargins ? vmargins(element) : 0);
	}

	function vpadding(element) {
		return (parseFloat($.css(element[0], 'paddingTop', true)) || 0) +
			(parseFloat($.css(element[0], 'paddingBottom', true)) || 0);
	}

	function vmargins(element) {
		return (parseFloat($.css(element[0], 'marginTop', true)) || 0) +
			(parseFloat($.css(element[0], 'marginBottom', true)) || 0);
	}

	function vborders(element) {
		return (parseFloat($.css(element[0], 'borderTopWidth', true)) || 0) +
			(parseFloat($.css(element[0], 'borderBottomWidth', true)) || 0);
	}

	function setMinHeight(element, height) {
		height = (typeof height == 'number' ? height + 'px' : height);
		element.each(function (i, _element) {
			_element.style.cssText += ';min-height:' + height + ';_height:' + height;
			// why can't we just use .css() ? i forget
		});
	}

	function smartProperty(obj, name) { // get a camel-cased/namespaced property of an object
		if (obj[name] !== undefined) {
			return obj[name];
		}
		var parts = name.split(/(?=[A-Z])/),
			i = parts.length - 1, res;
		for (; i >= 0; i--) {
			res = obj[parts[i].toLowerCase()];
			if (res !== undefined) {
				return res;
			}
		}
		return obj[''];
	}

	function htmlEscape(s) {
		return s.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/'/g, '&#039;')
			.replace(/"/g, '&quot;')
			.replace(/\n/g, '<br />');
	}

	function cssKey(_element) {
		return _element.id + '/' + _element.className + '/' + _element.style.cssText.replace(/(^|;)\s*(top|left|width|height)\s*:[^;]*/ig, '');
	}

	function markFirstLast(e) {
		e.children()
			.removeClass('fc-first fc-last')
			.filter(':first-child')
			.addClass('fc-first')
			.end()
			.filter(':last-child')
			.addClass('fc-last');
	}


	function setDayID(cell, date) {
		cell.each(function (i, _cell) {
			_cell.className = _cell.className.replace(/^fc-\w*/, 'fc-' + dayIDs[date.getDay()]);
			// TODO: make a way that doesn't rely on order of classes
		});
	}

	function firstDefined() {
		for (var i = 0; i < arguments.length; i++) {
			if (arguments[i] !== undefined) {
				return arguments[i];
			}
		}
	}

	fcViews.month = MonthView;

	function MonthView(element, calendar) {
		var t = this;

		// exports
		t.render = render;

		// imports
		BasicView.call(t, element, calendar, 'month');
		var opt = t.opt;
		var renderBasic = t.renderBasic;
		var formatDate = calendar.formatDate;

		function render(date, delta) {
			if (delta) {
				addMonths(date, delta);
				date.setDate(1);
			}
			var start = cloneDate(date, true);
			start.setDate(1);
			var end = addMonths(cloneDate(start), 1);
			var visStart = cloneDate(start);
			var visEnd = cloneDate(end);
			var firstDay = opt('firstDay');
			var nwe = opt('weekends') ? 0 : 1;
			if (nwe) {
				skipWeekend(visStart);
				skipWeekend(visEnd, -1, true);
			}
			addDays(visStart, -((visStart.getDay() - Math.max(firstDay, nwe) + 7) % 7));
			addDays(visEnd, (7 - visEnd.getDay() + Math.max(firstDay, nwe)) % 7);
			var rowCnt = Math.round((visEnd - visStart) / (DAY_MS * 7));
			if (opt('weekMode') == 'fixed') {
				addDays(visEnd, (6 - rowCnt) * 7);
				rowCnt = 5;//格數
			}
			t.title = formatDate(start, opt('titleFormat'));
			t.start = start;
			t.end = end;
			t.visStart = visStart;
			t.visEnd = visEnd;
			renderBasic(rowCnt, nwe ? 5 : 7, true);
		}
	}

	setDefaults({
		weekMode: 'fixed'
	});

	function BasicView(element, calendar, viewName) {
		var t = this;

		// exports
		t.renderBasic = renderBasic;
		t.setHeight = setHeight;
		t.setWidth = setWidth;
		t.defaultEventEnd = defaultEventEnd;
		t.getHoverListener = function () { return hoverListener };
		t.colContentLeft = colContentLeft;
		t.colContentRight = colContentRight;
		t.dayOfWeekCol = dayOfWeekCol;
		t.allDayRow = allDayRow;
		t.allDayBounds = allDayBounds;
		t.getRowCnt = function () { return rowCnt };
		t.getColCnt = function () { return colCnt };
		t.getColWidth = function () { return colWidth };
		t.getDaySegmentContainer = function () { return daySegmentContainer };

		// imports
		View.call(t, element, calendar, viewName);
		BasicEventRenderer.call(t);
		var opt = t.opt;
		var trigger = t.trigger;
		var clearEvents = t.clearEvents;
		var renderOverlay = t.renderOverlay;
		var clearOverlays = t.clearOverlays;
		var daySelectionMousedown = t.daySelectionMousedown;
		var formatDate = calendar.formatDate;

		// locals

		var table;
		var head;
		var headCells;
		var body;
		var bodyRows;
		var bodyCells;
		var bodyFirstCells;
		var bodyCellTopInners;
		var daySegmentContainer;

		var viewWidth;
		var viewHeight;
		var colWidth;
		var weekNumberWidth;

		var rowCnt, colCnt;
		var coordinateGrid;
		var hoverListener;
		var colContentPositions;

		var rtl, dis, dit;
		var firstDay;
		var nwe; // no weekends? a 0 or 1 for easy computations
		var tm;
		var colFormat;
		var showWeekNumbers;
		var weekNumberTitle;
		var weekNumberFormat;

		/* Rendering
		------------------------------------------------------------*/

		disableTextSelection(element.addClass('fc-grid'));

		function renderBasic(r, c, showNumbers) {
			rowCnt = r;
			colCnt = c;
			updateOptions();
			var firstTime = !body;
			if (firstTime) {
				buildEventContainer();
			} 
			buildTable(showNumbers);
		}

		//日期?
		function updateOptions() {
			rtl = opt('isRTL');
			if (rtl) {
				dis = -1;
				dit = colCnt - 1;
			} else {
				dis = 1;
				dit = 0;
			}
			firstDay = opt('firstDay');
			nwe = opt('weekends') ? 0 : 1;
			tm = opt('theme') ? 'ui' : 'fc';
			colFormat = opt('columnFormat');

		}

		function buildEventContainer() {
			daySegmentContainer =
				$("<div style='position:absolute;z-index:8;top:0;left:0'/>")
					.appendTo(element);
		}

		function buildTable(showNumbers) {
			var html = '';
			var i, j;
			var headerClass = tm + "-widget-header";
			var contentClass = tm + "-widget-content";
			var month = t.start.getMonth();
			var today = clearTime(new Date());
			var cellDate; // not to be confused with local function. TODO: better names
		

			html += "<table class='fc-border-separate' style='width:100%' cellspacing='0'>" +
				"<thead>" +
				"<tr>";

			if (showWeekNumbers) {
				html += "<th class='fc-week-number " + headerClass + "'/>";
			}

			for (i = 0; i < colCnt; i++) {
				cellDate = _cellDate(0, i); // a little confusing. cellDate is local variable. _cellDate is private function
				html += "<th class='fc-day-header fc-" + dayIDs[cellDate.getDay()] + " " + headerClass + "'/>";
			}

			html += "</tr>" +
				"</thead>" +
				"<tbody>";

			for (i = 0; i < rowCnt; i++) {
				html += "<tr class='fc-week'>";

				if (showWeekNumbers) {
					html += "<td class='fc-week-number " + contentClass + "'>" +
						"<div/>" +
						"</td>";
				}

				for (j = 0; j < colCnt; j++) {
					cellDate = _cellDate(i, j); // a little confusing. cellDate is local variable. _cellDate is private function

					cellClasses = [
						'fc-day',
						'fc-' + dayIDs[cellDate.getDay()],
						contentClass
					];
					if (cellDate.getMonth() != month) {
						cellClasses.push('fc-other-month');
					}
					if (+cellDate == +today) {
						cellClasses.push('fc-today');
						cellClasses.push(tm + '-state-highlight');
					}

					html += "<td" +
						" class='" + cellClasses.join(' ') + "'" +
						" data-date='" + formatDate(cellDate, 'yyyy-MM-dd') + "'" +
						">" +
						"<div>";
					if (showNumbers) {
						html += "<div class='fc-day-number'>" + cellDate.getDate() + "</div>";
					}
					html += "<div class='fc-day-content'>" +
						"<div style='position:relative'>&nbsp;</div>" +
						"</div>" +
						"</div>" +
						"</td>";
				}

				html += "</tr>";
			}
			html += "</tbody>" +
				"</table>";

			lockHeight(); // the unlock happens later, in setHeight()...
			if (table) {
				table.remove();
			}
			table = $(html).appendTo(element);

			head = table.find('thead');
			headCells = head.find('.fc-day-header');
			body = table.find('tbody');
			bodyRows = body.find('tr');
			bodyCells = body.find('.fc-day');
			bodyFirstCells = bodyRows.find('td:first-child');
			bodyCellTopInners = bodyRows.eq(0).find('.fc-day-content > div');

			markFirstLast(head.add(head.find('tr'))); // marks first+last tr/th's
			markFirstLast(bodyRows); // marks first+last td's
			bodyRows.eq(0).addClass('fc-first');
			bodyRows.filter(':last').addClass('fc-last');

			if (showWeekNumbers) {
				head.find('.fc-week-number').text(weekNumberTitle);
			}

			headCells.each(function (i, _cell) {
				var date = indexDate(i);
				$(_cell).text(formatDate(date, colFormat));
			});

			if (showWeekNumbers) {
				body.find('.fc-week-number > div').each(function (i, _cell) {
					var weekStart = _cellDate(i, 0);
					$(_cell).text(formatDate(weekStart, weekNumberFormat));
				});
			}

			bodyCells.each(function (i, _cell) {
				var date = indexDate(i);
				trigger('dayRender', t, date, $(_cell));
			});

			dayBind(bodyCells);
		}

		function setHeight(height) {
			viewHeight = height;

			var bodyHeight = viewHeight - head.height();
			var rowHeight;
			var rowHeightLast;
			var cell;

			if (opt('weekMode') == 'variable') {
				rowHeight = rowHeightLast = Math.floor(bodyHeight / (rowCnt == 1 ? 2 : 6));
			} else {
				rowHeight = Math.floor(bodyHeight / rowCnt);
				rowHeightLast = bodyHeight - rowHeight * (rowCnt - 1);
			}

			bodyFirstCells.each(function (i, _cell) {
				if (i < rowCnt) {
					cell = $(_cell);
					setMinHeight(
						cell.find('> div'),
						(i == rowCnt - 1 ? rowHeightLast : rowHeight) - vsides(cell)
					);
				}
			});
			unlockHeight();
		}

		function setWidth(width) {
			viewWidth = width;
			colContentPositions.clear();
			weekNumberWidth = 0;
			if (showWeekNumbers) {
				weekNumberWidth = head.find('th.fc-week-number').outerWidth();
			}
			colWidth = Math.floor((viewWidth - weekNumberWidth) / colCnt);
			setOuterWidth(headCells.slice(0, -1), colWidth);
		}

		hoverListener = new HoverListener(coordinateGrid);

		colContentPositions = new HorizontalPositionCache(function (col) {
			return bodyCellTopInners.eq(col);
		});

		function colContentLeft(col) {
			return colContentPositions.left(col);
		}

		function colContentRight(col) {
			return colContentPositions.right(col);
		}

		function _cellDate(row, col) {
			return addDays(cloneDate(t.visStart), row * 7 + col * dis + dit);
			// what about weekends in middle of week?
		}

		function indexDate(index) {
			return _cellDate(Math.floor(index / colCnt), index % colCnt);
		}

		function dayOfWeekCol(dayOfWeek) {
			return ((dayOfWeek - Math.max(firstDay, nwe) + colCnt) % colCnt) * dis + dit;
		}

		function allDayRow(i) {
			return bodyRows.eq(i);
		}

		function allDayBounds(i) {
			var left = 0;
			if (showWeekNumbers) {
				left += weekNumberWidth;
			}
			return {
				left: left,
				right: viewWidth
			};
		}
	}

	function BasicEventRenderer() {
		var t = this;

		// exports
		t.renderEvents = renderEvents;
		t.compileDaySegs = compileSegs; // for DayEventRenderer
		t.clearEvents = clearEvents;
		t.bindDaySeg = bindDaySeg;

		// imports
		DayEventRenderer.call(t);
		var opt = t.opt;
		var trigger = t.trigger;
		//var setOverflowHidden = t.setOverflowHidden;
		var isEventDraggable = t.isEventDraggable;
		var isEventResizable = t.isEventResizable;
		var reportEvents = t.reportEvents;
		var reportEventClear = t.reportEventClear;
		var eventElementHandlers = t.eventElementHandlers;
		var showEvents = t.showEvents;
		var hideEvents = t.hideEvents;
		var eventDrop = t.eventDrop;
		var getDaySegmentContainer = t.getDaySegmentContainer;
		var getHoverListener = t.getHoverListener;
		var renderDayOverlay = t.renderDayOverlay;
		var clearOverlays = t.clearOverlays;
		var getRowCnt = t.getRowCnt;
		var getColCnt = t.getColCnt;
		var renderDaySegs = t.renderDaySegs;
		var resizableDayEvent = t.resizableDayEvent;

		function renderEvents(events, modifiedEventId) {
			reportEvents(events);
			renderDaySegs(compileSegs(events), modifiedEventId);
			trigger('eventAfterAllRender');
		}

		function compileSegs(events) {
			var rowCnt = getRowCnt(),
				colCnt = getColCnt(),
				d1 = cloneDate(t.visStart),
				d2 = addDays(cloneDate(d1), colCnt),
				visEventsEnds = $.map(events, exclEndDay),
				i, row,
				j, level,
				k, seg,
				segs = [];
			for (i = 0; i < rowCnt; i++) {
				row = stackSegs(sliceSegs(events, visEventsEnds, d1, d2));
				for (j = 0; j < row.length; j++) {
					level = row[j];
					for (k = 0; k < level.length; k++) {
						seg = level[k];
						seg.row = i;
						seg.level = j; // not needed anymore
						segs.push(seg);
					}
				}
				addDays(d1, 7);
				addDays(d2, 7);
			}
			return segs;
		}
	}

	function View(element, calendar, viewName) {
		var t = this;
		// exports
		t.element = element;
		t.calendar = calendar;
		t.name = viewName;
		t.opt = opt;
		t.trigger = trigger;
		//t.setOverflowHidden = setOverflowHidden;
		t.isEventDraggable = isEventDraggable;
		t.isEventResizable = isEventResizable;
		t.reportEvents = reportEvents;
		t.eventEnd = eventEnd;
		t.reportEventElement = reportEventElement;
		t.reportEventClear = reportEventClear;
		t.eventElementHandlers = eventElementHandlers;
		t.showEvents = showEvents;
		t.hideEvents = hideEvents;
		t.eventDrop = eventDrop;
		t.eventResize = eventResize;

		// imports
		var defaultEventEnd = t.defaultEventEnd;
		var normalizeEvent = calendar.normalizeEvent; // in EventManager
		var reportEventChange = calendar.reportEventChange;

		// locals
		var eventsByID = {};
		var eventElements = [];
		var eventElementsByID = {};
		var options = calendar.options;

		function opt(name, viewNameOverride) {
			var v = options[name];
			if (typeof v == 'object') {
				return smartProperty(v, viewNameOverride || viewName);
			}
			return v;
		}
	}

	function DayEventRenderer() {
		var t = this;

		// exports
		t.renderDaySegs = renderDaySegs;
		t.resizableDayEvent = resizableDayEvent;

		// imports
		var opt = t.opt;
		var trigger = t.trigger;
		var isEventDraggable = t.isEventDraggable;
		var isEventResizable = t.isEventResizable;
		var eventEnd = t.eventEnd;
		var reportEventElement = t.reportEventElement;
		var showEvents = t.showEvents;
		var hideEvents = t.hideEvents;
		var eventResize = t.eventResize;
		var getRowCnt = t.getRowCnt;
		var getColCnt = t.getColCnt;
		var getColWidth = t.getColWidth;
		var allDayRow = t.allDayRow;
		var allDayBounds = t.allDayBounds;
		var colContentLeft = t.colContentLeft;
		var colContentRight = t.colContentRight;
		var dayOfWeekCol = t.dayOfWeekCol;
		var dateCell = t.dateCell;
		var compileDaySegs = t.compileDaySegs;
		var getDaySegmentContainer = t.getDaySegmentContainer;
		var bindDaySeg = t.bindDaySeg; //TODO: streamline this
		var formatDates = t.calendar.formatDates;
		var renderDayOverlay = t.renderDayOverlay;
		var clearOverlays = t.clearOverlays;
		var clearSelection = t.clearSelection;

		/* Rendering
		-----------------------------------------------------------------------------*/
		function renderDaySegs(segs, modifiedEventId) {
			var segmentContainer = getDaySegmentContainer();
			var rowDivs;
			var rowCnt = getRowCnt();
			var colCnt = getColCnt();
			var i = 0;
			var rowI;
			var levelI;
			var colHeights;
			var j;
			var segCnt = segs.length;
			var seg;
			var top;
			var k;
			segmentContainer[0].innerHTML = daySegHTML(segs); // faster than .html()
			daySegElementResolve(segs, segmentContainer.children());
			daySegElementReport(segs);
			daySegHandlers(segs, segmentContainer, modifiedEventId);
			daySegCalcHSides(segs);
			daySegSetWidths(segs);
			daySegCalcHeights(segs);
			rowDivs = getRowDivs();
			// set row heights, calculate event tops (in relation to row top)
			for (rowI = 0; rowI < rowCnt; rowI++) {
				levelI = 0;
				colHeights = [];
				for (j = 0; j < colCnt; j++) {
					colHeights[j] = 0;
				}
				while (i < segCnt && (seg = segs[i]).row == rowI) {
					// loop through segs in a row
					top = arrayMax(colHeights.slice(seg.startCol, seg.endCol));
					seg.top = top;
					top += seg.outerHeight;
					for (k = seg.startCol; k < seg.endCol; k++) {
						colHeights[k] = top;
					}
					i++;
				}
				rowDivs[rowI].height(arrayMax(colHeights));
			}
			daySegSetTops(segs, getRowTops(rowDivs));
		}

		function daySegHTML(segs) { // also sets seg.left and seg.outerWidth
			var rtl = opt('isRTL');
			var i;
			var segCnt = segs.length;
			var seg;
			var event;
			var url;
			var classes;
			var bounds = allDayBounds();
			var minLeft = bounds.left;
			var maxLeft = bounds.right;
			var leftCol;
			var rightCol;
			var left;
			var right;
			var skinCss;
			var html = '';
			// calculate desired position/dimensions, create html
			for (i = 0; i < segCnt; i++) {
				seg = segs[i];
				event = seg.event;
				classes = ['fc-event', 'fc-event-hori'];
				if (isEventDraggable(event)) {
					classes.push('fc-event-draggable');
				}
				if (seg.isStart) {
					classes.push('fc-event-start');
				}
				if (rtl) {
					leftCol = dayOfWeekCol(seg.end.getDay() - 1);
					rightCol = dayOfWeekCol(seg.start.getDay());
					left = seg.isEnd ? colContentLeft(leftCol) : minLeft;
					right = seg.isStart ? colContentRight(rightCol) : maxLeft;
				} else {
					leftCol = dayOfWeekCol(seg.start.getDay());
					rightCol = dayOfWeekCol(seg.end.getDay() - 1);
					left = seg.isStart ? colContentLeft(leftCol) : minLeft;
					right = seg.isEnd ? colContentRight(rightCol) : maxLeft;
				}
				classes = classes.concat(event.className);
				if (event.source) {
					classes = classes.concat(event.source.className || []);
				}
				skinCss = getSkinCss(event, opt);
				html += "<div" +
					" class='" + classes.join(' ') + "'" +
					" style='position:absolute;z-index:8;left:" + left + "px;" + skinCss + "'" +
					">" +
					"<div class='fc-event-inner'>";

				html +=
					"<span class='fc-event-title'>" + htmlEscape(event.title) + "</span>" +
					"</div></div>";//顯示標題
				seg.left = left;
				seg.outerWidth = right - left; // needs to be exclusive
			}
			return html;
		}

		function daySegElementResolve(segs, elements) { // sets seg.element
			var i;
			var segCnt = segs.length;
			var seg;
			var event;
			var element;
			var triggerRes;
			for (i = 0; i < segCnt; i++) {
				seg = segs[i];
				event = seg.event;
				element = $(elements[i]); // faster than .eq()
				triggerRes = trigger('eventRender', event, event, element);
				if (triggerRes === false) {
					element.remove();
				} else {
					if (triggerRes && triggerRes !== true) {
						triggerRes = $(triggerRes)
							.css({
								position: 'absolute',
								left: seg.left
							});
						element.replaceWith(triggerRes);
						element = triggerRes;
					}
					seg.element = element;
				}
			}
		}

		function daySegCalcHSides(segs) { // also sets seg.key
			var i;
			var segCnt = segs.length;
			var seg;
			var element;
			var key, val;
			var hsideCache = {};
			// record event horizontal sides
			for (i = 0; i < segCnt; i++) {
				seg = segs[i];
				element = seg.element;
				if (element) {
					key = seg.key = cssKey(element[0]);
					val = hsideCache[key];
					if (val === undefined) {
						val = hsideCache[key] = hsides(element, true);
					}
					seg.hsides = val;
				}
			}
		}

		function daySegSetWidths(segs) {
			var i;
			var segCnt = segs.length;
			var seg;
			var element;
			for (i = 0; i < segCnt; i++) {
				seg = segs[i];
				element = seg.element;
				if (element) {
					element[0].style.width = Math.max(0, seg.outerWidth - seg.hsides) + 'px';
				}
			}
		}

		function getRowDivs() {
			var i;
			var rowCnt = getRowCnt();
			var rowDivs = [];
			for (i = 0; i < rowCnt; i++) {
				rowDivs[i] = allDayRow(i)
					.find('div.fc-day-content > div'); // optimal selector?
			}
			return rowDivs;
		}

		function getRowTops(rowDivs) {
			var i;
			var rowCnt = rowDivs.length;
			var tops = [];
			for (i = 0; i < rowCnt; i++) {
				tops[i] = rowDivs[i][0].offsetTop; // !!?? but this means the element needs position:relative if in a table cell!!!!
			}
			return tops;
		}

		function daySegSetTops(segs, rowTops) { // also triggers eventAfterRender
			var i;
			var segCnt = segs.length;
			var seg;
			var element;
			var event;
			for (i = 0; i < segCnt; i++) {
				seg = segs[i];
				element = seg.element;
				if (element) {
					element[0].style.top = rowTops[seg.row] + (seg.top || 0) + 'px';
					event = seg.event;
					trigger('eventAfterRender', event, event, element);
				}
			}
		}}

	function HorizontalPositionCache(getElement) {
		var t = this,
			elements = {},
			lefts = {},
			rights = {};

		function e(i) {
			return elements[i] = elements[i] || getElement(i);
		}

		t.left = function (i) {
			return lefts[i] = lefts[i] === undefined ? e(i).position().left : lefts[i];
		};

		t.right = function (i) {
			return rights[i] = rights[i] === undefined ? t.left(i) + e(i).width() : rights[i];
		};

		t.clear = function () {
		};

	}

	;;
	//BUG: unselect needs to be triggered when events are dragged+dropped
	function resizableDayEvent(event, element, seg) {
	} function SelectionManager() {
	} function OverlayManager() {
	} function CoordinateGrid(buildFunc) {
	} function HoverListener(coordinateGrid) {
	} function destroy() {
	} function _fixUIEvent(event) { // for issue 1168
	} function bodyVisible() {
	} function updateSize() {
	} function markSizesDirty() {
	} function windowResize() {
	} function reportEventChange(eventID) {
	} function markEventsDirty() {
	} function select(start, end, allDay) {
	} function unselect() { // safe to be called before renderView
	} function prev() {
	} function next() {
	} function prevYear() {
	} function nextYear() {
	} function today() {
	} function gotoDate(year, month, dateOfMonth) {
	} function incrementDate(years, months, days) {
	} function getDate() {
	} function getView() {
	} function option(name, value) {
	} function trigger(name, thisObj) {
	} function addYears(d, n, keepTime) {
	} function addMonths(d, n, keepTime) { // prevents day overflow/underflow
	} function fixDate(d, check) { // force d to be on check's YMD, for daylight savings purposes
	} function addMinutes(d, n) {
	} function zeroDate() { // returns a Date with time 00:00:00 and dateOfMonth=1
	} function skipWeekend(date, inc, excl) {
	} function dayDiff(d1, d2) { // d1 - d2
	} function setYMD(date, y, m, d) {
	} function parseISO8601(s, ignoreTimezone) { // ignoreTimezone defaults to false
	} function parseTime(s) { // returns minutes since start of day
	} function _exclEndDay(end, allDay) {
	} function segCmp(a, b) {
	} function segsCollide(seg1, seg2) {
	} function lazySegBind(container, segs, bindHandlers) {
	} function setOuterWidth(element, width, includeMargins) {
	} function setOuterHeight(element, height, includeMargins) {
	} function noop() { } function cmp(a, b) {
	} function arrayMax(a) {
	} function zeroPad(n) {
	} function disableTextSelection(element) {
	} function getSkinCss(event, opt) {
	} function applyAll(functions, thisObj, args) {
	} function BasicWeekView(element, calendar) {
	} function BasicDayView(element, calendar) {
	} function dayBind(days) {
	} function dayClick(ev) {
	} function renderDayOverlay(overlayStart, overlayEnd, refreshCoordinateGrid) { // overlayEnd is exclusive
	} function renderCellOverlay(row0, col0, row1, col1) { // row1,col1 is inclusive
	} function defaultSelectionEnd(startDate, allDay) {
	} function renderSelection(startDate, endDate, allDay) {
	} function clearSelection() {
	} function reportDayClick(date, allDay, ev) {
	} function dragStart(_dragElement, ev, ui) {
	} function dragStop(_dragElement, ev, ui) {
	} function defaultEventEnd(event) {
	} function dateCell(date) {
	} function cellDate(cell) {
	} function lockHeight() {
	} function unlockHeight() {
	} function clearEvents() {
	} function bindDaySeg(event, eventElement, seg) {
	} function draggableDayEvent(event, eventElement) {
	} function AgendaWeekView(element, calendar) { } function AgendaDayView(element, calendar) {
	} function AgendaView(element, calendar, viewName) {
	} function AgendaEventRenderer() {
	} function countForwardSegs(levels) { } function trigger(name, thisObj) {
	} function isEventDraggable(event) {
	} function isEventResizable(event) { // but also need to make sure the seg.isEnd == true
	} function isEventEditable(event) {
	} function reportEvents(events) { // events are already normalized at this point
	} function eventEnd(event) {
	} function reportEventElement(event, element) {
	} function reportEventClear() {
	} function eventElementHandlers(event, eventElement) {
	} function showEvents(event, exceptElement) {
	} function hideEvents(event, exceptElement) {
	} function eachEventElement(event, exceptElement, funcName) {
	} function eventDrop(e, event, dayDelta, minuteDelta, allDay, ev, ui) {
	} function eventResize(e, event, dayDelta, minuteDelta, ev, ui) {
	} function moveEvents(events, dayDelta, minuteDelta, allDay) {
	} function elongateEvents(events, dayDelta, minuteDelta) {
	} function renderTempDaySegs(segs, adjustRow, adjustTop) {
	} function daySegElementReport(segs) {
	} function daySegHandlers(segs, segmentContainer, modifiedEventId) {
	} function daySegCalcHeights(segs) {
	} function addEventSource(source) {
	} function removeEventSource(source) {
	} function updateEvent(event) { // update an existing event
	} function renderEvent(event, stick) {
	} function removeEvents(filter) {
	} function clientEvents(filter) {
	} function pushLoading() {
	} function popLoading() {
	} function normalizeSource(source) {
	} function isSourcesEqual(source1, source2) {
	} function getSourcePrimitive(source) {
	}

})(jQuery);