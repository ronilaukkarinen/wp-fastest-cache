<?php
	$url = $_GET["url"];
	$url = str_replace(array("\"","'"), "", $url);
	$url = strip_tags($url);
	echo $url;
?>
<div id="wpfc-modal-downloaderror" style="top: 10.5px; left: 226px; position: absolute; padding: 6px; height: auto; width: 560px; z-index: 10001;">
	<div style="height: 100%; width: 100%; background: none repeat scroll 0% 0% rgb(0, 0, 0); position: absolute; top: 0px; left: 0px; z-index: -1; opacity: 0.5; border-radius: 8px;">
	</div>
	<div style="z-index: 600; border-radius: 3px;">
		<div style="font-family:Verdana,Geneva,Arial,Helvetica,sans-serif;font-size:12px;background: none repeat scroll 0px 0px rgb(255, 161, 0); z-index: 1000; position: relative; padding: 2px; border-bottom: 1px solid rgb(194, 122, 0); height: 35px; border-radius: 3px 3px 0px 0px;">
			<table width="100%" height="100%">
				<tbody>
					<tr>
						<td valign="middle" style="vertical-align: middle; font-weight: bold; color: rgb(255, 255, 255); text-shadow: 0px 1px 1px rgba(0, 0, 0, 0.5); padding-left: 10px; font-size: 13px; cursor: move;">Download Error</td>
						<td width="20" align="center" style="vertical-align: middle;"></td>
						<td width="20" align="center" style="vertical-align: middle; font-family: Arial,Helvetica,sans-serif; color: rgb(170, 170, 170); cursor: default;">
							<div title="Close Window" class="close-wiz"></div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<div class="window-content-wrapper" style="padding: 8px;">
			<div style="z-index: 1000; height: auto; position: relative; display: inline-block; width: 100%;" class="window-content">


				<div id="wpfc-wizard-downloaderror" class="wpfc-cdn-pages-container">
					<div wpfc-cdn-page="1" class="wiz-cont">
						<h1>Manually Activation</h1>		
						<p>/wp-content/plugins/ is not writable. You need to activate the premium plugin manually.</p>
						<div class="wiz-input-cont" style="text-align:center;" id="wpfc-send-email">
							<a href="<?php echo $url; ?>">
								<button class="wpfc-green-button" style="padding: 6px 60px;">
									<span>Download</span>
								</button>
							</a>
					    </div>
					    <p class="wpfc-bottom-note"><a target="_blank" href="http://www.wpfastestcache.com/warnings/how-to-activate-premium-version-manually/">Note: Please read How to Activate the Premium Version Manually</a></p>
					</div>
				</div>



			</div>
		</div>
	</div>
</div>