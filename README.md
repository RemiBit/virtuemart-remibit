VIRTUEMART REMIBIT EXTENSION

INSTALLATION AND CONFIGURATION


## Integration Requirements

* A RemiBit merchant account.
* VirtueMart 3, tested up to 3.8.4

## Extension Download

* Get the extension from virtuemart-remibit github Releases repository https://github.com/RemiBit/virtuemart-remibit/releases
* Right click `virtuemart-remibit.zip` and save it on your computer


## Install module in VirtueMart


1/. To upload the RemiBit Extension, either:
 
    * Go to `Extensions` > `Manage` > `Install` >  and upload `virtuemart-remibit.zip`
    * FTP upload `remibit` folder to `joomla_webroot/plugins/vmpayment/` (Go to `Extensions` > `Manange` > `Discover` to scan for the extension)

2/. Check that it is enabled in `Extensions` > `Manage` > `Manage`, filtering the list by typing `RemiBit` in the search box.


## Module Configuration

1/. Go to `VirtueMart` > `Payment Methods` and click `New` button on top to create a new payment method

2/. Fill up the following fields under `Payment Method Information` tab:

* Payment Name `RemiBit`
* Published `Yes`
* Currency `United States dollar`, `Euro`, etc.
* Click on `Save` button on top

3/. Choose `Configuration` tab and fill up the following fields from your RemiBit merchant account (`Settings` > `Gateway`):

* Login ID
* Transaction Key
* Signature Key
* MD5 Hash Value

