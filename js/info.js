;(function($) {
	$(".get-info").click(function(e){
		var input = $(this).prev(".inputCon").find("input");
		var options = {"h4": "", "h1" : ""};
		if(input.attr("id") == "wpFastestCacheNewPost"){

		}else if(input.attr("id") == "wpFastestCacheMinifyHtml"){
			options.h4 = "Minify HTML";
			options.h1 = "Compacting HTML code, including any inline JavaScript and CSS contained in it, can save many bytes of data and speed up downloading, parsing, and execution time.";
		}else if(input.attr("id") == "wpFastestCacheMinifyCss"){
			options.h4 = "Minify CSS";
			options.h1 = "Compacting CSS code can save many bytes of data and speed up downloading, parsing, and execution time.";
		}
		options.type = input.attr("id");
		modifyHelpTip(options);
	});
	function modifyHelpTip(options){
		var helpTip = $('<div id="rule-help-tip" style="display: block;"><div title="Close Window" class="close-window"> </div><h4></h4><h1 class="summary-rec"></h1><p></p></div>');
		var windowHeight;
		var windowWidth;

		helpTip.find("div.close-window").click(function(){
			helpTip.remove();
		});

		helpTip.attr("data-type", options.type);
		helpTip.find("h4").text(options.h4);
		helpTip.find("h1").text(options.h1);

		windowHeight = ($(window).height() - helpTip.height())/2;
		windowWidth = ($(window).width() - helpTip.width())/2;
		helpTip.css({"top": windowHeight, "left": windowWidth});

		var prevHelpTip = $('div[data-type="' + options.type + '"]');
		if(prevHelpTip.length > 0){
			prevHelpTip.remove();
		}else{
			if($('#rule-help-tip').length > 0){
				$('#rule-help-tip').remove();
			}
			$("body").append(helpTip);
		}
	}

})(jQuery);